<?
    // Klassendefinition
    class ADRA extends IPSModule {
 
        // Der Konstruktor des Moduls
        // Überschreibt den Standard Kontruktor von IPS
        public function __construct($InstanceID) {
            // Diese Zeile nicht löschen
            parent::__construct($InstanceID);
 
            // Selbsterstellter Code
        }
 
        // Überschreibt die interne IPS_Create($id) Funktion
        public function Create() {
            // Diese Zeile nicht löschen.
            parent::Create();
 

            //Connect Parent
            $this->RequireParent($this->module_interfaces['SerialPort']);
            $pid = $this->GetParent();
            if ($pid) {
                $name = IPS_GetName($pid);
                if ($name == "Serial Port") IPS_SetName($pid, __CLASS__ . " Port");
            }

        }
 
        /**
         * Destructor
         */
        public function Destroy()
        {
            parent::Destroy();
        }

        // Überschreibt die intere IPS_ApplyChanges($id) Funktion
        public function ApplyChanges() {
            // Diese Zeile nicht löschen
            parent::ApplyChanges();
        }
 
        // Ich empfange die Daten vom Parent im String $JSONString
        public function ReceiveData($JSONString)
        {
                $Data = json_decode($JSONString);
                IPS_LogMessage('Empfang Parent',print_r($Data,1));
                //'DataID' => GUID
                // Rest je nach Interface
        }

        public function SendCommand($Data)
        {
            $res = false;
            $json = json_encode(
                array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}",
                    "Buffer" => utf8_encode($Data)));
            if ($this->HasActiveParent()) {
                $res = parent::SendDataToParent($json);
            }
            return $res;
        }//function

        /**
        * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
        * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:
        *
        * ABC_MeineErsteEigeneFunktion($id);
        *
        */
        public function TestCommand($cmd) {
            // Selbsterstellter Code
            return $this->SendCommand($cmd);
        }
    }
?>