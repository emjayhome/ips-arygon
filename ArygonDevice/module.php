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
        $this->RegisterVariableString("UID", "UID", "", 0);
        $this->RegisterVariableInteger("ReaderState", "ReaderState", "", 1);
        //$this->CheckParent();
    }

    private function CheckParent() {
        $result = $this->HasActiveParent();
        if ($result) {
            $instance = IPS_GetInstance($this->InstanceID);
            $parentGUID = IPS_GetInstance($instance['ConnectionID'])['ModuleInfo']['ModuleID'];
            if ($parentGUID != '{2F75DC6B-F9AA-4A2E-B121-F76FBC365566}') {
                IPS_LogMessage('ArygonDevice', 'Parent not supported.');
                $this->SetStatus(202);
                $this->UpdateReaderState(ArygonDeviceState::Inactive);
                $result = false;
            } else {
               $this->SetStatus(102); 
               $this->UpdateReaderState(ArygonDeviceState::Idle);
            }
        }
        return $result;
    }

    protected function HasActiveParent() {         
        $instance = IPS_GetInstance($this->InstanceID);
        if ($instance['ConnectionID'] > 0) {
            $parent = IPS_GetInstance($instance['ConnectionID']);
            if ($parent['InstanceStatus'] == 102) {
                $this->UpdateReaderState(ArygonDeviceState::Idle);
                return true;
            } else {
                $this->SetStatus(104);
                $this->UpdateReaderState(ArygonDeviceState::Inactive);
            }
        } else { // No parent
            $this->SetStatus(201);
            $this->UpdateReaderState(ArygonDeviceState::Inactive);
        }
        IPS_LogMessage('ArygonDevice', 'No active parent.');
        return false;
    }

    private function UpdateReaderState($state) {
        $ReaderStateID = $this->GetIDForIdent('ReaderState');
        SetValueInteger($ReaderStateID, $state);  
    }

    private function GetReaderState() {
        $ReaderStateID = $this->GetIDForIdent('ReaderState');
        return GetValueInteger($ReaderStateID);  
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

    protected function RegisterTimer($Name, $Interval, $Script) {
        $id = @IPS_GetObjectIDByIdent($Name, $this->InstanceID);
        if ($id === false)
            $id = 0;


        if ($id > 0)
        {
            if (!IPS_EventExists($id))
                throw new Exception("Ident with name " . $Name . " is used for wrong object type", E_USER_NOTICE);

            if (IPS_GetEvent($id)['EventType'] <> 1)
            {
                IPS_DeleteEvent($id);
                $id = 0;
            }
        }

        if ($id == 0)
        {
            $id = IPS_CreateEvent(1);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $Name);
        } IPS_SetName($id, $Name);
        IPS_SetHidden($id, true);
        IPS_SetEventScript($id, $Script);
        if ($Interval > 0)
        {
            IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, $Interval);

            IPS_SetEventActive($id, true);
        } else
        {
            IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, 1);

            IPS_SetEventActive($id, false);
        }
    }

    protected function UnregisterTimer($Name)
    {
        $id = @IPS_GetObjectIDByIdent($Name, $this->InstanceID);
        if ($id > 0)
        {
            if (!IPS_EventExists($id))
                throw new Exception('Timer not present', E_USER_NOTICE);
            IPS_DeleteEvent($id);
        }
    }

    protected function SetTimerInterval($Name, $Interval)
    {
        $id = @IPS_GetObjectIDByIdent($Name, $this->InstanceID);
        if ($id === false)
            throw new Exception('Timer not present', E_USER_NOTICE);
        if (!IPS_EventExists($id))
            throw new Exception('Timer not present', E_USER_NOTICE);

        $Event = IPS_GetEvent($id);

        if ($Interval < 1)
        {
            if ($Event['EventActive'])
                IPS_SetEventActive($id, false);
        }
        else
        {
            if
            ($Event['CyclicTimeValue'] <> $Interval)
                IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, $Interval)
                ;
            if (!$Event['EventActive'])
                IPS_SetEventActive($id, true);
        }
    }

    // IPS raw data iterface for parent (splitter)
    public function ReceiveData($JSONString) {
        $ResponseData = json_decode($JSONString);
        //IPS_LogMessage('ArygonDevice', 'ReceiveData JSON: ' . $JSONString);
        if ($ResponseData->DataID <> '{35B444C9-CDC0-4F0F-BEBD-A5BDD29D07A4}') {
            return false;
        }

        $Response = new ArygonResponseASCII();
        $Response->GetDataFromJSONObject($ResponseData);
        
        // New UID?
        if(($Response->GetUserDataLength()) > 16 && ($Response->GetUserData()[0] == '4') && ($Response->GetUserData()[1] == 'B')) {
            $length = hexdec(substr($Response->GetUserData(), 12, 2));
            $uid = substr($Response->GetUserData(), 14, $length*2);
            $UidID = $this->GetIDForIdent('UID');
            SetValueString($UidID, $uid); 
            return true;
        }

        if (!$this->lock('ResponseData')) {
            return false;
            //throw new Exception('ResponseData is locked', E_USER_NOTICE);
        }
        $ResponseDataID = $this->GetIDForIdent('ResponseData');
        SetValueString($ResponseDataID, $Response->GetRawResponse());
        $this->unlock('ResponseData');
        return true;
    }

    public function HandleNewUid($UID) {
        $this->CardHold();
        $this->DoubleBeep();
        if($Polling) {
            $this->StartPolling();
        } 
    }

    // Reinitialize reader
    public function ResetReader() {

        //$this->CheckParent();

        //if($this->GetReaderState() <= ArygonDeviceState::Inactive) {
        //    IPS_LogMessage('ArygonDevice', 'Module is inactive');
        //    return false;
        //}

        if(!$this->StopContinuousBeep()) {
            return false;
        }

        // Initiate uC software reset (TAMA is reset as well)
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('au');
        try {
            $this->Send($Command, true);
            IPS_LogMessage('ArygonDevice', 'Reset OK');
        } catch(Exception $exc) {
            IPS_LogMessage('ArygonDevice', 'Reset failed');
            return false;
        }

        // Get uC firmware version
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('av');
        try {
            $result = $this->Send($Command, true);
            IPS_LogMessage('ArygonDevice', 'Firmware version: ' . $result->GetUserData());
        } catch(Exception $exc) {
            IPS_LogMessage('ArygonDevice', 'Read firmware version failed');
            return false;
        }

        // Get the unique serial number of the reader
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('asn');
        try {
            $result = $this->Send($Command, true);
            IPS_LogMessage('ArygonDevice', 'Serial number: ' . $result->GetUserData());  
        } catch(Exception $exc) {
            IPS_LogMessage('ArygonDevice', 'Read serial number failed');
            return false;
        }

        // Configuration of the GPIO pins
        try {
            // Buzzer: Port 0 -> output
            $Command = new ArygonCommandASCII();
            $Command->SetCommand('apc');
            $Command->SetData('0000');
            $this->Send($Command, true); 

            // Red LED: Port 2 -> output
            $Command = new ArygonCommandASCII();
            $Command->SetCommand('apc');
            $Command->SetData('0200');
            $this->Send($Command, true); 

            // Green LED: Port 6 -> output
            $Command = new ArygonCommandASCII();
            $Command->SetCommand('apc');
            $Command->SetData('0600');
            $this->Send($Command, true); 
        } catch(Exception $exc) {
            IPS_LogMessage('ArygonDevice', 'Configure GPIO pins failed');
            return false;
        }

        // Start polling
        return $this->StartPolling();

    }

    public function StartPolling() {
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('s');
        try {
            $this->Send($Command, true);
        } catch (Exception $exc) {
            IPS_LogMessage('ArygonDevice', 'StartPolling exception: ' . $exc->getMessage());
        }
    }

    public function StopPolling() {
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('of');
        $Command->SetData('00');
        try {
            $this->Send($Command, true);
        } catch (Exception $exc) {
            IPS_LogMessage('ArygonDevice', 'StopPolling exception: ' . $exc->getMessage());
        }
    }

    private function CardHold() {
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('h');
        $Command->SetData('00');
        try {
            $this->Send($Command, true);
        } catch (Exception $exc) {
            IPS_LogMessage('ArygonDevice', 'CardHold exception: ' . $exc->getMessage());
            unset($exc);
        }       
    }

    public function StartContinuousBeep() {
        try {
            $this->RegisterTimer('Beep', 1, 'ADRA_Beep($_IPS[\'TARGET\']);');  
            return true;
        } catch (Exception $exc) {
            return false;
        }   
    }

    public function StopContinuousBeep() {
        try {
            $this->UnregisterTimer('Beep');
            return true;
        } catch (Exception $exc) {
            return false;
        }
    }

    public function DoubleBeep() {
        $this->Beep();
        $this->Beep();
    }

    public function Beep() {
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('apw');
        $Command->SetData('0001'); // Buzzer: Port 0 -> high
        try {
            $this->Send($Command, true); 
        } catch (Exception $exc) {
            IPS_LogMessage('ArygonDevice', 'Buzzer on exception: ' . $exc->getMessage());
            unset($exc);
            return;
        }

        IPS_Sleep(20);

        $Command = new ArygonCommandASCII();
        $Command->SetCommand('apw');
        $Command->SetData('0000'); // Buzzer: Port 0 -> low
        try {
            $this->Send($Command, true); 
        } catch (Exception $exc) {
            IPS_LogMessage('ArygonDevice', 'Buzzer off exception: ' . $exc->getMessage());
            unset($exc);
            return;
        }

    }



    private function Send(ArygonCommandASCII $Command, $needResponse = true) {
        //if (!$this->HasActiveParent()) {
        //    throw new Exception("Instance has no active Parent.", E_USER_NOTICE);
        //}

        $ResponseDataID = $this->GetIDForIdent('ResponseData');
        if (!$this->lock('RequestSendData')) {
            throw new Exception('RequestSendData is locked', E_USER_NOTICE);
        }

        if ($needResponse) {
            if (!$this->lock('ResponseData')) {
                $this->unlock('RequestSendData');
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
        return @IPS_SendDataToParent($this->InstanceID, $JSONString);
    }

    // Command/response protocol - wait for response
    private function WaitForResponse() {
        $ResponseDataID = $this->GetIDForIdent('ResponseData');
        for ($i = 0; $i < 200; $i++) {
            if (GetValueString($ResponseDataID) === '') {
                IPS_Sleep(5);
            } else {
                if ($this->lock('ResponseData')) {
                    $ret = GetValueString($ResponseDataID);
                    SetValueString($ResponseDataID, '');
                    $this->unlock('ResponseData');
                    $Response = new ArygonResponseASCII();
                    $Response->SetResponse($ret);
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