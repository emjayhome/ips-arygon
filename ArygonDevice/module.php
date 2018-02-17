<?

require_once(__DIR__ . "/../ArygonData.php");
require_once(__DIR__ . "/../IpsIncludes.php");

class ArygonDevice extends IPSModule {

    // IPS module functions

    public function Create() {
        parent::Create();
        $this->RegisterVariableString("ResponseData", "ResponseData", "", -1);
        $this->RegisterVariableString("UID", "UID", "", 0);
        $this->RegisterVariableInteger("ReaderState", "ReaderState", "", 1);
        $this->ConnectParent("{2F75DC6B-F9AA-4A2E-B121-F76FBC365566}");
        $this->RegisterTimer("Beep", 0, 'ADRA_Beep($_IPS[\'TARGET\']);');
    }

	public function Destroy(){
		//Never delete this line!
		parent::Destroy();
		
	}

    public function ApplyChanges() {
        parent::ApplyChanges();

    }

    // IPS raw data iterface for parent (splitter)
    public function ReceiveData($JSONString) {
        
        $ResponseData = json_decode($JSONString);

        $Response = new ArygonResponseASCII();
        $Response->GetDataFromJSONObject($ResponseData);
        
       // New UID?
        if(($Response->GetUserDataLength()) > 16 && ($Response->GetUserData()[0] == '4') && ($Response->GetUserData()[1] == 'B')) {
            $this->StopContinuousBeep();
            $length = hexdec(substr($Response->GetUserData(), 12, 2));
            $uid = substr($Response->GetUserData(), 14, $length*2);
            $UidID = $this->GetIDForIdent('UID');
            SetValueString($UidID, $uid); 
            return true;
        }

        if (!$this->lock('ResponseData')) {
            return false;
        }
        $ResponseDataID = $this->GetIDForIdent('ResponseData');
        SetValueString($ResponseDataID, $Response->GetRawResponse());
        $this->unlock('ResponseData');
        return true;
    }

    public function HandleNewUid($UID) {
        $this->CardHold();
        $this->DoubleBeep();
        $this->StartPolling();
    }

    // Reinitialize reader
    public function ResetReader() {

        $this->StopContinuousBeep();

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
            return false;
        }
        return true;
    }

    public function StopPolling() {
        $Command = new ArygonCommandASCII();
        $Command->SetCommand('of');
        $Command->SetData('00');
        try {
            $this->Send($Command, true);
        } catch (Exception $exc) {
            IPS_LogMessage('ArygonDevice', 'StopPolling exception: ' . $exc->getMessage());
            return false;
        }
        return true;
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
        $this->SetTimerInterval("Beep", 1000);  
    }

    public function StopContinuousBeep() {
        $this->SetTimerInterval("Beep", 0);
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

        $ret = $this->SendDataToParent($Command->ToJSONString('{62096A8D-6F10-4E1D-A51F-0EDFD09DCF44}'));
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
            if (IPS_SemaphoreEnter("ADRA1_" . (string) $this->InstanceID . (string) $ident, 1)) {
                return true;
            } else {
                IPS_Sleep(mt_rand(1, 5));
            }
        }
        return false;
    }

    private function unlock($ident) {
        IPS_SemaphoreLeave("ADRA1_" . (string) $this->InstanceID . (string) $ident);
    }

}

?>