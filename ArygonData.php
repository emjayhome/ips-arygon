<?

class ArygonCommandASCII {
    private $Mode = '0';
    private $Command = '';
    private $Data = '';

    public function GetCommand() {
        return $this->Mode . $this->Command . $this->Data;
    }

    public function SetCommand($cmd) {
        $this->Command = $cmd;
    }

    public function SetData($data) {
        $this->Data = $data;
    }

    public function GetDataFromJSONObject($Data) {
        $this->Command = utf8_decode($Data->Command);
        $this->Data = utf8_decode($Data->Data);
    }

    public function ToJSONString($GUID) {
        $SendData = new stdClass;
        $SendData->DataID = $GUID;
        $SendData->Command = utf8_encode($this->Command);
        $SendData->Data = utf8_encode($this->Data);
        return json_encode($SendData);
    }

}

class ArygonResponseASCII {
    private $Response;

    public function IsOK() {
        if($this->GetErrorCode() == 0) {
            return true;
        }
        return false;
    }

    public function GetErrorCode() {
        return hexdec(substr($this->Response, 2, 2));
    }

    public function GetSubErrorCode() {
        return hexdec(substr($this->Response, 4, 2));
    }

    public function  GetUserDataLength() {
        return hexdec(substr($this->Response, 6, 2));
    }

    public function GetUserData() {
        return substr($this->Response, 8, $this->GetUserDataLength);
    }

    public function GetRawResponse() {
        return $this->Response;
    }

    public function SetResponse($RawData) {
        $this->Response = $RawData;
    }

    public function GetDataFromJSONObject($Data) {
        $this->Response = utf8_decode($Data->Response);
    }

    public function ToJSONString($GUID) {
        $SendData = new stdClass;
        $SendData->DataID = $GUID;
        $SendData->Response = utf8_encode($this->Response);
        return json_encode($SendData);
    }

} 

?>