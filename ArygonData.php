<?

    class ArygonCommandASCII {
        private $Mode = '0';
        private $Command = '';
        private $Data = '';

        public function GetCommand() {
            return $Mode . $Command . $Data;
        }

        public function SetCommand($cmd) {
            $this->Command = $cmd;
        }

        public function SetData($data) {
            $this->Data = $data;
        }

        public function GetDataFromJSONObject($Data) {
            $this->Command = $Data->Command;
            $this->Data = $Data->Data;
        }

        public function ToJSONString($GUID) {
            $SendData = new stdClass;
            $SendData->DataID = $GUID;
            $SendData->Command = $this->Command;
            $SendData->Data = $this->Data;
            return json_encode($SendData);
        }

    }

    class ArygonResponseASCII {
        private $ASCIIResponse;
        private $HexResponse;

        public function GetErrorCode() {
            return $this->HexResponse[1];
        }

        public function GetSubErrorCode() {
            return $this->HexResponse[2];
        }
 
        public function  GetUserDataLength() {
            return $this->HexResponse[3];
        }

        public function GetUserData() {
            return '';
        }

        public function GetRawResponse() {
            return $this->ASCIIResponse;
        }

        public function SetResponse($RawData) {
            $this->ASCIIResponse = $RawData;
            $this->HexResponse = hex2bin($this->ASCIIResponse);
        }

        public function GetDataFromJSONObject($Data) {
            $this->RawResponse = $Data->RawResponse;
            $this->HexResponse = hex2bin($this->ASCIIResponse);
        }

        public function ToJSONString($GUID) {
            $SendData = new stdClass;
            $SendData->RawResponse = bin2hex($this->HexResponse);
            return json_encode($SendData);
        }

    } 

}

?>