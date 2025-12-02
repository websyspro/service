# Tutorial: Criando um Conector SQL Server com Sockets em PHP

## 📋 Índice
1. [Introdução](#introdução)
2. [Protocolo TDS (Tabular Data Stream)](#protocolo-tds)
3. [Estrutura Básica](#estrutura-básica)
4. [Passo 1: Conexão Socket](#passo-1-conexão-socket)
5. [Passo 2: Pre-Login Packet](#passo-2-pre-login-packet)
6. [Passo 3: Login Packet](#passo-3-login-packet)
7. [Passo 4: Executar Queries](#passo-4-executar-queries)
8. [Implementação Completa](#implementação-completa)

---

## Introdução

Este tutorial ensina como criar um conector SQL Server nativo usando sockets TCP, sem dependências externas. Seguiremos o protocolo **TDS (Tabular Data Stream)** usado pelo SQL Server.

**Vantagens:**
- ✅ Zero dependências
- ✅ Performance máxima
- ✅ Controle total do protocolo
- ✅ Compatível com SQL Server 2008+

---

## Protocolo TDS (Tabular Data Stream)

O SQL Server usa o protocolo TDS para comunicação. Principais características:

- **Porta padrão:** 1433
- **Estrutura:** Header (8 bytes) + Data
- **Tipos de packet:** Pre-Login, Login, SQL Batch, etc.

### Estrutura do Header TDS:
```
Byte 0: Packet Type
Byte 1: Status  
Byte 2-3: Length (big-endian)
Byte 4-5: SPID
Byte 6: Packet ID
Byte 7: Window
```

---

## Estrutura Básica

Vamos criar as classes fundamentais:

```php
class TDSPacketHead {
    public function __construct(
        public int $type,
        public int $status,
        public int $length,
        public int $spid = 0,
        public int $packetId = 0,
        public int $window = 0
    ) {}
}

class TDSPacketBody {
    public function __construct(
        public string $data
    ) {}
}

class SQLServerConnector {
    private $socket;
    private bool $connected = false;
    
    public function __construct(
        private string $host,
        private int $port = 1433,
        private string $user,
        private string $pass,
        private string $database
    ) {}
}
```

---

## Passo 1: Conexão Socket

### 1.1 Abrir Socket TCP

```php
public function connect(): void {
    $this->socket = @fsockopen(
        $this->host,
        $this->port,
        $errno,
        $errstr,
        5 // timeout
    );
    
    if (!$this->socket) {
        throw new Exception("Falha na conexão: $errstr");
    }
    
    $this->sendPreLogin();
}
```

**Resultado esperado:** Socket TCP aberto na porta 1433

---

## Passo 2: Pre-Login Packet

### 2.1 Enviar Pre-Login

O Pre-Login é o primeiro packet enviado ao SQL Server.

```php
private function sendPreLogin(): void {
    // Pre-Login options
    $options = [
        0x00 => pack('C', 0x06), // Version
        0x01 => pack('C', 0x01), // Encryption (off)
        0x02 => pack('C', 0x00), // InstOpt
        0x03 => 'ODBC',          // ThreadId
        0xFF => ''               // Terminator
    ];
    
    $data = $this->buildPreLoginData($options);
    $this->sendTDSPacket(0x12, $data); // 0x12 = Pre-Login
}

private function buildPreLoginData(array $options): string {
    $data = '';
    $offset = (count($options) - 1) * 5 + 1; // Calculate offset
    
    foreach ($options as $type => $value) {
        if ($type === 0xFF) {
            $data .= pack('C', 0xFF); // Terminator
            break;
        }
        
        $data .= pack('C', $type);           // Option type
        $data .= pack('n', $offset);         // Offset (big-endian)
        $data .= pack('n', strlen($value));  // Length (big-endian)
        $offset += strlen($value);
    }
    
    // Add option values
    foreach ($options as $type => $value) {
        if ($type !== 0xFF) {
            $data .= $value;
        }
    }
    
    return $data;
}
```

### 2.2 Ler Resposta Pre-Login

```php
private function readPreLoginResponse(): void {
    $header = $this->readTDSHeader();
    $data = $this->readTDSData($header->length - 8);
    
    // Parse pre-login response
    // Verificar se servidor aceita conexão
}
```

**Resultado esperado:** Resposta Pre-Login do servidor confirmando parâmetros

---

## Passo 3: Login Packet

### 3.1 Construir Login7 Packet

```php
private function sendLogin(): void {
    $login = $this->buildLogin7Packet();
    $this->sendTDSPacket(0x10, $login); // 0x10 = Login7
}

private function buildLogin7Packet(): string {
    $packet = '';
    
    // Login7 header (36 bytes)
    $packet .= pack('V', 36);                    // Length
    $packet .= pack('V', 0x74000004);           // TDS Version
    $packet .= pack('V', 4096);                 // Packet Size
    $packet .= pack('V', 0x00000007);           // Client Version
    $packet .= pack('V', getmypid());           // Client PID
    $packet .= pack('V', 0);                    // Connection ID
    $packet .= pack('C', 0xE0);                 // Option Flags 1
    $packet .= pack('C', 0x03);                 // Option Flags 2
    $packet .= pack('C', 0x00);                 // Type Flags
    $packet .= pack('C', 0x00);                 // Reserved
    $packet .= pack('V', 0);                    // Time Zone
    $packet .= pack('V', 0);                    // Collation
    
    // Variable length data
    $offset = 36;
    $data = '';
    
    // Client name
    $clientName = 'PHP-TDS';
    $packet .= pack('v', $offset);              // Offset
    $packet .= pack('v', strlen($clientName));  // Length
    $data .= $this->stringToUCS2($clientName);
    $offset += strlen($clientName) * 2;
    
    // Username
    $packet .= pack('v', $offset);
    $packet .= pack('v', strlen($this->user));
    $data .= $this->stringToUCS2($this->user);
    $offset += strlen($this->user) * 2;
    
    // Password
    $packet .= pack('v', $offset);
    $packet .= pack('v', strlen($this->pass));
    $data .= $this->encodePassword($this->pass);
    $offset += strlen($this->pass) * 2;
    
    // App name
    $appName = 'PHP Application';
    $packet .= pack('v', $offset);
    $packet .= pack('v', strlen($appName));
    $data .= $this->stringToUCS2($appName);
    $offset += strlen($appName) * 2;
    
    // Server name
    $packet .= pack('v', $offset);
    $packet .= pack('v', strlen($this->host));
    $data .= $this->stringToUCS2($this->host);
    $offset += strlen($this->host) * 2;
    
    // Library name
    $libName = 'PHP-TDS';
    $packet .= pack('v', $offset);
    $packet .= pack('v', strlen($libName));
    $data .= $this->stringToUCS2($libName);
    $offset += strlen($libName) * 2;
    
    // Language
    $packet .= pack('v', 0);  // Offset
    $packet .= pack('v', 0);  // Length
    
    // Database
    $packet .= pack('v', $offset);
    $packet .= pack('v', strlen($this->database));
    $data .= $this->stringToUCS2($this->database);
    
    return $packet . $data;
}

private function stringToUCS2(string $str): string {
    return mb_convert_encoding($str, 'UCS-2LE', 'UTF-8');
}

private function encodePassword(string $password): string {
    $encoded = '';
    for ($i = 0; $i < strlen($password); $i++) {
        $char = ord($password[$i]);
        $encoded .= chr((($char & 0x0F) << 4) | (($char & 0xF0) >> 4));
        $encoded .= chr(0x00); // UCS-2 padding
    }
    return $encoded;
}
```

### 3.2 Processar Resposta Login

```php
private function processLoginResponse(): void {
    $header = $this->readTDSHeader();
    $data = $this->readTDSData($header->length - 8);
    
    $offset = 0;
    while ($offset < strlen($data)) {
        $token = ord($data[$offset++]);
        
        switch ($token) {
            case 0xAD: // Login acknowledgment
                $this->connected = true;
                break;
                
            case 0xAA: // Error
                $this->handleError($data, $offset);
                break;
                
            case 0xAB: // Info
                $this->handleInfo($data, $offset);
                break;
                
            case 0xFD: // Done
                return;
        }
    }
}
```

**Resultado esperado:** Token 0xAD (Login ACK) confirmando autenticação

---

## Passo 4: Executar Queries

### 4.1 Enviar SQL Batch

```php
public function query(string $sql): array {
    $sqlUCS2 = $this->stringToUCS2($sql);
    $this->sendTDSPacket(0x01, $sqlUCS2); // 0x01 = SQL Batch
    
    return $this->readQueryResults();
}

private function readQueryResults(): array {
    $results = [];
    $columns = [];
    
    while (true) {
        $header = $this->readTDSHeader();
        $data = $this->readTDSData($header->length - 8);
        
        $offset = 0;
        while ($offset < strlen($data)) {
            $token = ord($data[$offset++]);
            
            switch ($token) {
                case 0x81: // Column metadata
                    $columns = $this->parseColumnMetadata($data, $offset);
                    break;
                    
                case 0xD1: // Row data
                    $row = $this->parseRowData($data, $offset, $columns);
                    $results[] = $row;
                    break;
                    
                case 0xFD: // Done
                    return $results;
            }
        }
        
        if ($header->status & 0x01) { // Last packet
            break;
        }
    }
    
    return $results;
}
```

### 4.2 Parse Column Metadata

```php
private function parseColumnMetadata(string $data, int &$offset): array {
    $columnCount = unpack('v', substr($data, $offset, 2))[1];
    $offset += 2;
    
    $columns = [];
    for ($i = 0; $i < $columnCount; $i++) {
        $column = [];
        
        // User type
        $offset += 4;
        
        // Flags
        $flags = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;
        
        // Type info
        $type = ord($data[$offset++]);
        $column['type'] = $type;
        
        // Length based on type
        switch ($type) {
            case 0x26: // INT
            case 0x38: // INT
                $column['length'] = 4;
                break;
                
            case 0xA7: // VARCHAR
            case 0xE7: // NVARCHAR
                $length = unpack('v', substr($data, $offset, 2))[1];
                $offset += 2;
                $column['length'] = $length;
                break;
        }
        
        // Column name
        $nameLength = ord($data[$offset++]);
        $column['name'] = mb_convert_encoding(
            substr($data, $offset, $nameLength * 2),
            'UTF-8',
            'UCS-2LE'
        );
        $offset += $nameLength * 2;
        
        $columns[] = $column;
    }
    
    return $columns;
}
```

**Resultado esperado:** Array com metadados das colunas e dados das linhas

---

## Implementação Completa

### Métodos Auxiliares

```php
private function sendTDSPacket(int $type, string $data): void {
    $length = strlen($data) + 8;
    
    $header = pack('C', $type);           // Packet type
    $header .= pack('C', 0x01);           // Status (end of message)
    $header .= pack('n', $length);        // Length (big-endian)
    $header .= pack('n', 0);              // SPID
    $header .= pack('C', 0);              // Packet ID
    $header .= pack('C', 0);              // Window
    
    fwrite($this->socket, $header . $data);
}

private function readTDSHeader(): TDSPacketHead {
    $header = fread($this->socket, 8);
    
    return new TDSPacketHead(
        ord($header[0]),                    // Type
        ord($header[1]),                    // Status
        unpack('n', substr($header, 2, 2))[1], // Length
        unpack('n', substr($header, 4, 2))[1], // SPID
        ord($header[6]),                    // Packet ID
        ord($header[7])                     // Window
    );
}

private function readTDSData(int $length): string {
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($this->socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new Exception('Connection lost');
        }
        $data .= $chunk;
    }
    return $data;
}
```

### Exemplo de Uso

```php
$connector = new SQLServerConnector(
    'localhost',
    1433,
    'sa',
    'password',
    'master'
);

$connector->connect();

$results = $connector->query("SELECT name FROM sys.databases");
foreach ($results as $row) {
    echo $row['name'] . "\n";
}
```

---

## 🎯 Próximos Passos

1. **Implementar prepared statements**
2. **Adicionar suporte a transações**
3. **Melhorar tratamento de erros**
4. **Suporte a tipos de dados complexos**
5. **Pool de conexões**

---

## 📚 Referências

- [Microsoft TDS Protocol Documentation](https://docs.microsoft.com/en-us/openspecs/windows_protocols/ms-tds/)
- [SQL Server Network Protocol](https://docs.microsoft.com/en-us/sql/database-engine/configure-windows/server-network-configuration)

---

**Autor:** Tutorial baseado na implementação do MySQLConnector  
**Data:** 2024  
**Versão:** 1.0