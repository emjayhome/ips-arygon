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
    }

    private function CheckParents() {
        $result = $this->HasActiveParent();
        if ($result) {
            $instance = IPS_GetInstance($this->InstanceID);
            $parentGUID = IPS_GetInstance($instance['ConnectionID'])['ModuleInfo']['ModuleID'];
            if ($parentGUID != '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}') {
                IPS_LogMessage('Arygon Splitter', 'Parent not supported.');
                $result = false;
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
            }
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
            IPS_LogMessage('ArygonSplitter', 'Received unsupported data from child.');
            return false;
        }
        $Command = new ArygonCommandASCII();
        $Command->GetDataFromJSONObject($Data);
        try {
            $this->ForwardCommandFromChild($Command);
        } catch (Exception $ex) {
            trigger_error($ex->getMessage(), $ex->getCode());
            IPS_LogMessage('ArygonSplitter', 'Exception: ' . $ex->getMessage());
            return false;
        }
        return true;
    }

    // Forward command from child (device) to parent (serial interface)
    private function ForwardCommandFromChild(ArygonCommandASCII $Command) {
        if (!$this->CheckParents()) {
            throw new Exception("Instance has no active parent.", E_USER_NOTICE);
        }

        $Raw = $Command->GetCommand();
        IPS_LogMessage('ArygonSplitter', 'Command: ' . $Raw);

        if (!$this->lock("ToParent")) {
            throw new Exception("Can not send to parent.", E_USER_NOTICE);
        }

        try {
            IPS_SendDataToParent($this->InstanceID, json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", "Buffer" => utf8_encode($Raw))));
        } catch (Exception $exc) {
            $this->unlock("ToParent");
            throw new Exception($exc);
        }

        $this->unlock("ToParent");
        return true;
    }

    // IPS raw data iterface for parent (serial interface) to child (device) forwarding
    public function ReceiveData($JSONString)
    {
        IPS_LogMessage('ArygonSplitter', $JSONString);
        $data = json_decode($JSONString);
        
        $this->CheckParents();

        $bufferID = $this->GetIDForIdent("BufferIn");
        
        if (!$this->lock("ReceiveLock")) {
            trigger_error("ReceiveBuffer is locked.", E_USER_NOTICE);
            return false;
        }

        $head = GetValueString($bufferID);
        SetValueString($bufferID, '');
        $stream = $head . utf8_decode($data->Buffer);

        IPS_LogMessage('ArygonSplitter', 'Stream: ' . $stream);    

        $minTail = 8;
        $start = strpos($stream, 'FF');
        if ($start === false) {
            IPS_LogMessage('Arygon Splitter', 'Response Packet without FF');
            $stream = '';
        } elseif ($start > 0) {
            IPS_LogMessage('Arygon Splitter', 'Response Packet did not start with FF');
            $stream = substr($stream, $start);
        }
        $end = strpos($stream, '\r\n');
        if ($end === false) {
            SetValueString($bufferID, $stream);
            $this->unlock("ReceiveLock");
            return;
        } else {
            $stream = substr($stream, 0, $end);
        }

        if(strlen($stream) < $minTail) {
            IPS_LogMessage('Arygon Splitter', 'Response Packet too short');
            $this->unlock("ReceiveLock");
            return;
        }

        $this->unlock("ReceiveLock");

        $Response = new ArygonResponseASCII();
        $Response->SetResponse($stream);
        $this->SendResponseToChild($Response);
        IPS_LogMessage('ArygonSplitter', 'Response: ' . $stream);

        return true;
    }

    // Forward response from parent (serial interface) to child (device)
    private function SendResponseToChild(ArygonResponseASCII $Response) {
        $Data = $Response->ToJSONString('{43E4B48E-2345-4A9A-B506-3E8E7A964757}');
        IPS_LogMessage('ArygonSplitter', $Data);
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
        return false;
    }

    private function unlock($ident) {
        IPS_SemaphoreLeave("ADRAS_" . (string) $this->InstanceID . (string) $ident);
    }

}

?>