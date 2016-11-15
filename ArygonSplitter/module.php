<?

require_once(__DIR__ . "/../ArygonData.php");

class ArygonSplitter extends IPSModule {

    // IPS module functions

    public function Create() {
        parent::Create();
        $this->RequireParent("{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->RegisterVariableString("BufferIn", "BufferIn", "", -1);
        IPS_SetHidden($this->GetIDForIdent('BufferIn'), true);
        //$this->CheckParents();
    }

    private function CheckParents() {
        $result = $this->HasActiveParent();
        if ($result) {
            $instance = IPS_GetInstance($this->InstanceID);
            $parentGUID = IPS_GetInstance($instance['ConnectionID'])['ModuleInfo']['ModuleID'];
            if ($parentGUID != '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}') {
                IPS_LogMessage('Arygon Splitter', 'Parent not supported.');
                $this->SetStatus(202);
                $result = false;
            } else {
               $this->SetStatus(102); 
            }
        }
        return $result;
    }

    protected function HasActiveParent() {         
        $instance = IPS_GetInstance($this->InstanceID);
        if ($instance['ConnectionID'] > 0) {
            $parent = IPS_GetInstance($instance['ConnectionID']);
            if ($parent['InstanceStatus'] == 102) {
                return true;
            } else {
                $this->SetStatus(104);
            }
        } else { // No parent
            $this->SetStatus(201);
        }
        IPS_LogMessage('ArygonSplitter', 'No active parent.');
        return false;
    }

    protected function SetStatus($InstanceStatus) {
        if ($InstanceStatus <> IPS_GetInstance($this->InstanceID)['InstanceStatus']) {
            parent::SetStatus($InstanceStatus);
        }
    }

    // IPS raw data iterface for child (device) to parent (serial interface) forwarding
    public function ForwardData($JSONString) {
        $Data = json_decode($JSONString);
        if ($Data->DataID <> "{62096A8D-6F10-4E1D-A51F-0EDFD09DCF44}") {
            return false;
        }
        $Command = new ArygonCommandASCII();
        $Command->GetDataFromJSONObject($Data);
        try {
            $this->ForwardCommandFromChild($Command);
        } catch (Exception $ex) {
            IPS_LogMessage('ArygonSplitter', 'Forward Data Exception: ' . $ex->getMessage());
            trigger_error($ex->getMessage(), $ex->getCode());
            return false;
        }
        return true;
    }

    // Forward command from child (device) to parent (serial interface)
    private function ForwardCommandFromChild(ArygonCommandASCII $Command) {
        //if (!$this->CheckParents()) {
        //    throw new Exception("Instance has no active parent.", E_USER_NOTICE);
        //}

        /*
        $bufferID = $this->GetIDForIdent("BufferIn");

        if (!$this->lock("ReceiveLock")) {
            throw new Exception("ReceiveBuffer is locked.", E_USER_NOTICE);
        }
        SetValueString($bufferID, '');
        $this->unlock("ReceiveLock");
        */

        $Raw = $Command->GetCommand();

        if (!$this->lock("ToParent")) {
            throw new Exception("Can not send to parent.", E_USER_NOTICE);
        }

        try {
            IPS_LogMessage('ArygonSplitter', 'Sending raw: ' . utf8_encode($Raw));
            IPS_SendDataToParent($this->InstanceID, json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", "Buffer" => utf8_encode($Raw))));
        } catch (Exception $exc) {
            $this->unlock("ToParent");
            IPS_LogMessage('ArygonSplitter', 'Forward Command Exception: ' . $ex->getMessage());
            throw new Exception($exc);
        }

        $this->unlock("ToParent");
        return true;
    }

    // IPS raw data iterface for parent (serial interface)
    public function ReceiveData($JSONString) {

        $data = json_decode($JSONString);
        $bufferID = $this->GetIDForIdent("BufferIn");
        
        if (!$this->lock("ReceiveLock")) {
            trigger_error("ReceiveBuffer is locked.", E_USER_NOTICE);
            return false;
        }

        $head = GetValueString($bufferID);
        SetValueString($bufferID, '');
        IPS_LogMessage('ArygonSplitter', 'Receiving raw: ' . utf8_decode($data->Buffer));
        $stream = $head . utf8_decode($data->Buffer);

        $minLength = 10;
        $dataResponse = true;
        if(strlen($stream) < $minLength) {
            SetValueString($bufferID, $stream);
            $this->unlock("ReceiveLock");
            IPS_LogMessage('ArygonSplitter', 'Too short');
            return false;            
        }
        $start = strpos($stream, 'FF');
        if ($start === false) {
            $dataResonse = false;
        } elseif ($start > 0) {
            $stream = substr($stream, $start);
        }
        $end = strpos($stream, "\r\n");
        if ($end === false) {
            SetValueString($bufferID, $stream);
            $this->unlock("ReceiveLock");
            IPS_LogMessage('ArygonSplitter', 'Missing end');
            return false;
        } else {
            $head = substr($stream, 0, $end);
            $tail = substr($stream, $end);
        }

        $this->unlock("ReceiveLock");

        if ($dataResponse) {   
            $Response = new ArygonResponseASCII();
            $Response->SetResponse($head);
            $this->SendResponseToChild($Response);
        }

        if (strlen($tail) >= $minLength) {
            $this->ReceiveData(json_encode(array('Buffer' => '')));
        }

        IPS_LogMessage('ArygonSplitter', 'Success');
        return true;
    }

    // Forward response to child (device)
    private function SendResponseToChild(ArygonResponseASCII $Response) {
        $Data = $Response->ToJSONString('{35B444C9-CDC0-4F0F-BEBD-A5BDD29D07A4}');
        IPS_SendDataToChildren($this->InstanceID, $Data);
    }

    // Semaphore helpers

    private function lock($ident) {
        for ($i = 0; $i < 100; $i++) {
            if (IPS_SemaphoreEnter("ADRAS_" . (string) $this->InstanceID . (string) $ident, 1)) {
                return true;
            } else {
                IPS_Sleep(mt_rand(1, 5));
            }
        }
        IPS_LogMessage('ArygonSplitter', 'Failed to lock ' . $ident);
        return false;
    }

    private function unlock($ident) {
        IPS_SemaphoreLeave("ADRAS_" . (string) $this->InstanceID . (string) $ident);
    }

}

?>