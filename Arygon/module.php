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
            $this->RequireParent("{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}");

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

        //prüfen ob IO ein SerialPort ist
        //        
        // Zwangskonfiguration des SerialPort, wenn vorhanden und verbunden
        // Aber nie bei einem Neustart :)
        if (IPS_GetKernelRunlevel() == KR_READY)
        {
            $ParentID = $this->GetParent();
            if (!($ParentID === false))
            {
                $ParentInstance = IPS_GetInstance($ParentID);
                if ($ParentInstance['ModuleInfo']['ModuleID'] == '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}')
                {
                    if (IPS_GetProperty($ParentID, 'StopBits') <> '1')
                        IPS_SetProperty($ParentID, 'StopBits', '1');
                    if (IPS_GetProperty($ParentID, 'BaudRate') <> '9600')
                        IPS_SetProperty($ParentID, 'BaudRate', '9600');
                    if (IPS_GetProperty($ParentID, 'Parity') <> 'None')
                        IPS_SetProperty($ParentID, 'Parity', 'None');
                    if (IPS_GetProperty($ParentID, 'DataBits') <> '8')
                        IPS_SetProperty($ParentID, 'DataBits', '8');
                    if (IPS_HasChanges($ParentID))
                        IPS_ApplyChanges($ParentID);
                }
            }
        }
        try
        {
            $this->SenCommand("0asn");
        } catch (Exception $exc)
        {
            if (IPS_GetKernelRunlevel() == KR_READY)
                trigger_error($exc->getMessage(), $exc->getCode());
        }

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


    protected function GetParent()
    {
        $instance = @IPS_GetInstance($this->InstanceID);
        return ($instance['ConnectionID'] > 0) ? $instance['ConnectionID'] : false;
    }

?>