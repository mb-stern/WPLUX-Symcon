<?php

class WPLUXSymcon extends IPSModule
{
    private $updateTimer;

    protected function Log($Message)
    {
        IPS_LogMessage(__CLASS__, $Message);
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('IPAddress', '192.168.178.59');
        $this->RegisterPropertyInteger('Port', 8889);
        $this->RegisterPropertyString('IDListe', '[]');
        $this->RegisterPropertyInteger('UpdateInterval', 0);

        // Timer für Aktualisierung registrieren
        $this->RegisterTimer('UpdateTimer', 0, 'WPLUX_Update(' . $this->InstanceID . ');');

        // Benötigte Varaiblen erstellen
        if (!IPS_VariableProfileExists("WPLUX.Sec")) {
			IPS_CreateVariableProfile("WPLUX.Sec", 2); //2 für Float
			IPS_SetVariableProfileValues("WPLUX.Sec", 0, 0, 1); //Min, Max, Schritt
            IPS_SetVariableProfileDigits("WPLUX.Sec", 0); //Nachkommastellen
			IPS_SetVariableProfileText("WPLUX.Sec", "", " sec"); //Präfix, Suffix
		}
        if (!IPS_VariableProfileExists("WPLUX.Imp")) {
			IPS_CreateVariableProfile("WPLUX.Imp", 2); //2 für Float
			IPS_SetVariableProfileValues("WPLUX.Imp", 0, 0, 1); //Min, Max, Schritt
            IPS_SetVariableProfileDigits("WPLUX.Imp", 0); //Nachkommastellen
			IPS_SetVariableProfileText("WPLUX.Imp", "", " imp"); //Präfix, Suffix
		}
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        // Timer für Aktualisierung aktualisieren
        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);

        // Bei Änderungen am Konfigurationsformular oder bei der Initialisierung auslösen
        $this->Update();
    }

    public function Update()
    {
        //Verbindung zur Lux
        $IpWwc = "{$this->ReadPropertyString('IPAddress')}";
        $WwcJavaPort = "{$this->ReadPropertyInteger('Port')}";
        $SiteTitle = "WÄRMEPUMPE";

        //Debug senden
        $this->SendDebug("Verbindungseinstellung im Config", "".$IpWwc.":".$WwcJavaPort."", 0);

        // Integriere Variabelbenennung aus den Java Daten
        require_once __DIR__ . '/java_daten.php';

        // Lese die ID-Liste
        $idListe = json_decode($this->ReadPropertyString('IDListe'), true);

        // Socket verbinden
        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
        $connect = socket_connect($socket, $IpWwc, $WwcJavaPort);

        //Debug senden
        if (!$connect) {
            $error_code = socket_last_error();
            $this->SendDebug("Verbindung zum Socket fehlgeschlagen. Error:", "$error_code", 0);
        } else {
            $this->SendDebug("Verbindung zum Socket erfolgreich", "".$IpWwc.":".$WwcJavaPort."", 0);
        }

        // Daten holen
        $msg = pack('N*',3004);
        $send=socket_write($socket, $msg, 4); //3004 senden

        $msg = pack('N*',0);
        $send=socket_write($socket, $msg, 4); //0 senden

        socket_recv($socket,$Test,4,MSG_WAITALL);  // Lesen, sollte 3004 zurückkommen
        $Test = unpack('N*',$Test);

        socket_recv($socket,$Test,4,MSG_WAITALL); // Status
        $Test = unpack('N*',$Test);

        socket_recv($socket,$Test,4,MSG_WAITALL); // Länge der nachfolgenden Werte
        $Test = unpack('N*',$Test);

        $JavaWerte = implode($Test);

        for ($i = 0; $i < $JavaWerte; ++$i)//vorwärts
        {
            socket_recv($socket,$InBuff[$i],4,MSG_WAITALL);  // Lesen, sollte 3004 zurückkommen
            $daten_raw[$i] = implode(unpack('N*',$InBuff[$i]));
        }

        //socket wieder schließen
        socket_close($socket);

        // Werte anzeigen
        for ($i = 0; $i < $JavaWerte; ++$i) {
        if (in_array($i, array_column($idListe, 'id'))) {
        $value = $this->convertValueBasedOnID($daten_raw[$i], $i);

        // Debug senden
        $this->SendDebug("ID : Wert der Abfrage", "".$i." : ".$value."", 0);

        // Direkte Erstellung oder Aktualisierung der Variable mit Ident und Positionsnummer
        $ident = 'WP_' . $java_dataset[$i];
        $varid = $this->CreateOrUpdateVariable($ident, $value, $i);
        } else {
        // Variable löschen, da sie nicht mehr in der ID-Liste ist
        $this->DeleteVariableIfExists('WP_' . $java_dataset[$i]);
            }
        }
    }
                
    private function AssignVariableProfilesAndType($varid, $id)
    {
        // Hier erfolgt die Zuordnung des Variablenprofils und -typs basierend auf der 'id'
        switch (true) {

            case ($id >= 10 && $id <= 28):
                if ($varid > 0) {
                    IPS_SetVariableCustomProfile($varid, '~Temperature');
                }
                return 2; // Float-Typ
            
            case ($id >= 29 && $id <= 55):
                if ($varid > 0) {
                    IPS_SetVariableCustomProfile($varid, '~Switch');
                }
                return 0; // Boolean-Typ

            case ($id == 56 || $id == 58 || ($id >= 60 && $id <= 77)):
                if ($varid > 0) {
                    IPS_SetVariableCustomProfile($varid, 'WPLUX.Sec');
                    }
                return 2; // Float-Typ
            
            case ($id == 57 || $id == 59):
                if ($varid > 0) {
                    IPS_SetVariableCustomProfile($varid, 'WPLUX.Imp');
                    }
                return 2; // Float-Typ
                /*
            case ($id == 29):
                    if ($varid > 0) {
                        IPS_SetVariableCustomProfile($varid, '~Switch');
                    }
                    return 0; // Boolean-Typ
                    */
            
            // Weitere Zuordnungen für andere 'id'-Bereiche hinzufügen
            default:
                // Standardprofil, falls keine spezifische Zuordnung gefunden wird
                if ($varid > 0) {
                    IPS_SetVariableCustomProfile($varid, '');
                }
                return 1; // Standardmäßig Integer-Typ
        }
    }
    
    private function convertValueBasedOnID($value, $id)
    {
        // Hier erfolgt die Konvertierung des Werts basierend auf der 'id'
        switch ($id) {
        
        case ($id >= 10 && $id <= 28):
            return round($value * 0.1, 1);
        
        /*
        case ($id >= 29 && $id <= 55):
            return boolval($value);
        */    
        
        // Weitere Zuordnungen für andere 'id' hinzufügen
        default:
            return round($value * 1, 1); // Standardmäßig Konvertierung
        }
    }
            
    private function CreateOrUpdateVariable($ident, $value, $id)
    {
        $value = $this->convertValueBasedOnID($value, $id);

        // Überprüfen, ob die Variable bereits existiert
        $existingVarID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        if ($existingVarID === false) {
            // Variable existiert nicht, also erstellen
            $varid = IPS_CreateVariable($this->AssignVariableProfilesAndType(null, $id));
            IPS_SetParent($varid, $this->InstanceID);
            IPS_SetIdent($varid, $ident);
            IPS_SetName($varid, $ident);
            SetValue($varid, $value);
            IPS_SetPosition($varid, $id);

            // Hier die Methode aufrufen, um das Profil zuzuweisen
            $this->AssignVariableProfilesAndType($varid, $id);
        } else {
            // Variable existiert, also aktualisieren
            $varid = $existingVarID;
            // Überprüfen, ob der Variablentyp stimmt
            if (IPS_GetVariable($varid)['VariableType'] != $this->AssignVariableProfilesAndType($varid, $id)) {
                // Variablentyp stimmt nicht überein, also Variable neu erstellen
                IPS_DeleteVariable($varid);
                $varid = IPS_CreateVariable($this->AssignVariableProfilesAndType(null, $id));
                IPS_SetParent($varid, $this->InstanceID);
                IPS_SetIdent($varid, $ident);
                IPS_SetName($varid, $ident);
                SetValue($varid, $value);
                IPS_SetPosition($varid, $id);
            } else {
                // Variablentyp stimmt überein, also nur Wert aktualisieren
                SetValue($varid, $value);
            }
        }
        return $varid;
    }

    private function DeleteVariableIfExists($ident)
    {
        $variableID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($variableID !== false) {
            // Debug-Ausgabe
            $this->SendDebug("Variable gelöscht", "$ident", 0);
                
            // Variable löschen
            IPS_DeleteVariable($variableID);
        }
    }
}