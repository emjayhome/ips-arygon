<?

require_once(__DIR__ . "/../ArygonData.php");
require_once(__DIR__ . "/../IpsIncludes.php");

class ArygonDevice extends IPSModule {

    // IPS module functions

    public function Create() {
        parent::Create();
        $this->ConnectParent("{2F75DC6B-F9AA-4A2E-B121-F76FBC365566}");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->RegisterVariableString("ResponseData", "ResponseData", "", -1);
        IPS_SetHidden($this->GetIDForIdent('ResponseData'), true);
        $this->RegisterVariableString("CardUID", "CardUID", "", 0);
        $this->RegisterVariableString("ReaderState", "ReaderState", "", 1);
  
        try {
            $this->ResetReader();
        } catch (Exception $ex) {
            unset($ex);
        }
    }

    protected function HasActiveParent() {         
        $instance = IPS_GetInstance($this->InstanceID);
        IPS_LogMessage('ArygonDevice', serialize($instance));
        if ($instance['ConnectionID'] > 0) {
            $parent = IPS_GetInstance($instance['ConnectionID']);
            IPS_LogMessage('ArygonDevice', serialize($parent));
            if ($parent['InstanceStatus'] == 102) {
                return true;
            }
        }
        IPS_LogMessage('ArygonDevice', $parent['InstanceStatus']);
        return false;
    }

    protected function GetVariable($Ident, $VarType, $VarName, $Profile, $EnableAction)
    {
        $VarID = @$this->GetIDForIdent($Ident);
        if ($VarID > 0)
        {
            if (IPS_GetVariable($VarID)['VariableType'] <> $VarType)
            {
                IPS_DeleteVariable($VarID);
                $VarID = false;
            }
        }
        if ($VarID === false)
        {
            $this->MaintainVariable($Ident, $VarName, $VarType, $Profile, 0, true);
            if ($EnableAction)
                $this->MaintainAction($Ident, true);
            $VarID = $this->GetIDForIdent($Ident);
        }
        return $VarID;
    }

    protected function SetStatus($InstanceStatus) {
        if ($InstanceStatus <> IPS_GetInstance($this->InstanceID)['InstanceStatus']) {
            parent::SetStatus($InstanceStatus);
        }
    }

    // IPS raw data iterface for parent (splitter)
    public function ReceiveData($JSONString) {
        $ResponseData = json_decode($JSONString);

        if ($ResponseData->DataID <> '{35B444C9-CDC0-4F0F-BEBD-A5BDD29D07A4}') {
            return false;
        }

        $Response = new ArygonResponseASCII();
        $Response->GetDataFromJSONObject($ResponseData);

        if (!$this->lock('ResponseData')) {
            throw new Exception('ResponseData is locked', E_USER_NOTICE);
        }
        SetValueString($ResponseDataID, $Response->GetRawResponse());
        $this->unlock('ResponseData');
    }

    // Reinitialize reader
    public function ResetReader() {

        IPS_LogMessage('ArygonDevice', 'Reset reader ...');

        // Initiate uC software reset (TAMA is reset as well)
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('au');
        try {
            $this->Send($Command, true);
        } catch (Exception $exc) {
            IPS_LogMessage('ArygonData', 'Exception: ' . $ex->getMessage());
            unset($exc);
        }

        IPS_LogMessage('ArygonDevice', 'Reset OK');

        // Get uC firmware version
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('av');
        try {
            $result = $this->Send($Command, true);
            IPS_LogMessage('ArygonDevice', 'Firmware version: ' . $result->GetUserData());
        } catch (Exception $exc) {
            IPS_LogMessage('ArygonData', 'Exception: ' . $ex->getMessage());
            unset($exc);
        }

        // Get the unique serial number of the reader
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('asn');
        try {
            $result = $this->Send($Command, true);
            IPS_LogMessage('ArygonDevice', 'Serial number: ' . $result->GetUserData());  
        } catch (Exception $exc) {
            IPS_LogMessage('ArygonData', 'Exception: ' . $ex->getMessage());
            unset($exc);
        }     

    }

    private function Send(ArygonCommandASCII $Command, $needResponse = true) {
        IPS_LogMessage('ArygonDevice', $Command->ToJSONString('Test'));
        if (!$this->HasActiveParent()) {
            IPS_LogMessage('ArygonDevice', 'No active parent');
            throw new Exception("Instance has no active Parent.", E_USER_NOTICE);
        }

        $ResponseDataID = $this->GetIDForIdent('ResponseData');
        if (!$this->lock('RequestSendData')) {
            IPS_LogMessage('ArygonDevice', 'RequestSendData is locked');
            throw new Exception('RequestSendData is locked', E_USER_NOTICE);
        }

        if ($needResponse) {
            if (!$this->lock('ResponseData')) {
                $this->unlock('RequestSendData');
                IPS_LogMessage('ArygonDevice', 'ResponseData is locked');
                throw new Exception('ResponseData is locked', E_USER_NOTICE);
            }
            SetValueString($ResponseDataID, '');
            $this->unlock('ResponseData');
        }

        $ret = $this->SendDataToParent($Command);
        if ($ret === false) {
            $this->unlock('RequestSendData');
            throw new Exception('Instance has no active Parent Instance!', E_USER_NOTICE);
        }

        if (!$needResponse) {
            $this->unlock('RequestSendData');
            return true;
        }
        $Response = $this->WaitForResponse();

        if ($Response === false) {
            $this->unlock('RequestSendData');
            throw new Exception('Send Data Timeout', E_USER_NOTICE);
        }

        $this->unlock('RequestSendData');
        return $Response;
    }

    protected function SendDataToParent($Data) {
        $JSONString = $Data->ToJSONString('{62096A8D-6F10-4E1D-A51F-0EDFD09DCF44}');
        IPS_LogMessage('ArygonData', $JSONString);
        return @IPS_SendDataToParent($this->InstanceID, $JSONString);
    }

    // Command/response protocol - wait for response
    private function WaitForResponse() {
        $ResponseDataID = $this->GetIDForIdent('ResponseData');
        for ($i = 0; $i < 50; $i++) {
            if (GetValueString($ResponseDataID) === '') {
                IPS_Sleep(50);
            } else {
                if ($this->lock('ResponseData')) {
                    $ret = GetValueString($ResponseDataID);
                    SetValueString($ResponseDataID, '');
                    $this->unlock('ResponseData');
                    $JSON = json_decode($ret);
                    $Response = new ArygonResponseASCII();
                    $Response->GetDataFromJSONObject($JSON);
                    return $Response;
                }
            }
        }
        if ($this->lock('ResponseData')) {
            SetValueString($ResponseDataID, '');
            $this->unlock('ResponseData');
        }

        return false;
    }

    // Semaphore helpers

    private function lock($ident) {
        for ($i = 0; $i < 100; $i++) {
            if (IPS_SemaphoreEnter("ADRA_" . (string) $this->InstanceID . (string) $ident, 1)) {
                return true;
            } else {
                IPS_Sleep(mt_rand(1, 5));
            }
        }
        return false;
    }

    private function unlock($ident) {
        IPS_SemaphoreLeave("ADRA_" . (string) $this->InstanceID . (string) $ident);
    }

}

?>