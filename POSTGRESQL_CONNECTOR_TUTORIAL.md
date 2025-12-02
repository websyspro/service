# Tutorial: Criando um Conector PostgreSQL com Sockets em PHP

## 📋 Índice
1. [Introdução](#introdução)
2. [Protocolo PostgreSQL](#protocolo-postgresql)
3. [Estrutura Básica](#estrutura-básica)
4. [Passo 1: Conexão Socket](#passo-1-conexão-socket)
5. [Passo 2: Startup Message](#passo-2-startup-message)
6. [Passo 3: Autenticação](#passo-3-autenticação)
7. [Passo 4: Ready for Query](#passo-4-ready-for-query)
8. [Passo 5: Executar Queries](#passo-5-executar-queries)
9. [Implementação Completa](#implementação-completa)

---

## Introdução

Este tutorial ensina como criar um conector PostgreSQL nativo usando sockets TCP, sem dependências externas. Seguiremos o **PostgreSQL Frontend/Backend Protocol**.

**Vantagens:**
- ✅ Zero dependências
- ✅ Performance máxima
- ✅ Controle total do protocolo
- ✅ Compatível com PostgreSQL 9.0+

---

## Protocolo PostgreSQL

O PostgreSQL usa um protocolo baseado em mensagens. Principais características:

- **Porta padrão:** 5432
- **Estrutura:** Type (1 byte) + Length (4 bytes) + Data
- **Fases:** Startup → Authentication → Ready → Query/Response

### Estrutura das Mensagens:
```
Frontend Messages:
Byte 0: Message Type (opcional no startup)
Byte 1-4: Length (big-endian, inclui os 4 bytes do length)
Byte 5+: Message Data

Backend Messages:
Byte 0: Message Type
Byte 1-4: Length (big-endian)
Byte 5+: Message Data
```

---

## Estrutura Básica

Vamos criar as classes fundamentais:

```php
class PGMessage {
    public function __construct(
        public string $type,
        public string $data
    ) {}
    
    public function pack(): string {
        $length = strlen($this->data) + 4;
        return $this->type . pack('N', $length) . $this->data;
    }
}

class PGStartupMessage {
    public function __construct(
        public array $parameters
    ) {}
    
    public function pack(): string {
        $data = pack('N', 196608); // Protocol version 3.0
        
        foreach ($this->parameters as $key => $value) {
            $data .= $key . "\0" . $value . "\0";
        }
        $data .= "\0"; // Terminator
        
        $length = strlen($data) + 4;
        return pack('N', $length) . $data;
    }
}

class PostgreSQLConnector {
    private $socket;
    private bool $connected = false;
    private array $parameters = [];
    
    public function __construct(
        private string $host,
        private int $port = 5432,
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
    
    $this->sendStartupMessage();
}
```

**Resultado esperado:** Socket TCP aberto na porta 5432

---

## Passo 2: Startup Message

### 2.1 Enviar Startup Message

O Startup Message é a primeira mensagem enviada ao PostgreSQL.

```php
private function sendStartupMessage(): void {
    $startup = new PGStartupMessage([
        'user' => $this->user,
        'database' => $this->database,
        'application_name' => 'PHP-PG',
        'client_encoding' => 'UTF8'
    ]);
    
    fwrite($this->socket, $startup->pack());
    $this->handleAuthenticationFlow();
}
```

**Resultado esperado:** Servidor responde com Authentication request

---

## Passo 3: Autenticação

### 3.1 Processar Authentication Flow

```php
private function handleAuthenticationFlow(): void {
    while (true) {
        $message = $this->readMessage();
        
        switch ($message->type) {
            case 'R': // Authentication
                $this->handleAuthentication($message->data);
                break;
                
            case 'S': // Parameter Status
                $this->handleParameterStatus($message->data);
                break;
                
            case 'K': // Backend Key Data
                $this->handleBackendKeyData($message->data);
                break;
                
            case 'Z': // Ready for Query
                $this->connected = true;
                return;
                
            case 'E': // Error
                $this->handleError($message->data);
                break;
        }
    }
}

private function handleAuthentication(string $data): void {
    $authType = unpack('N', substr($data, 0, 4))[1];
    
    switch ($authType) {
        case 0: // Authentication OK
            break;
            
        case 3: // Clear text password
            $this->sendPasswordMessage($this->pass);
            break;
            
        case 5: // MD5 password
            $salt = substr($data, 4, 4);
            $hashedPassword = $this->md5Password($this->user, $this->pass, $salt);
            $this->sendPasswordMessage($hashedPassword);
            break;
            
        case 10: // SASL
            $this->handleSASLAuthentication($data);
            break;
            
        default:
            throw new Exception("Unsupported authentication type: $authType");
    }
}

private function md5Password(string $user, string $pass, string $salt): string {
    $hash1 = md5($pass . $user);
    $hash2 = md5(hex2bin($hash1) . $salt);
    return 'md5' . $hash2;
}

private function sendPasswordMessage(string $password): void {
    $message = new PGMessage('p', $password . "\0");
    fwrite($this->socket, $message->pack());
}
```

### 3.2 SASL Authentication (SCRAM-SHA-256)

```php
private function handleSASLAuthentication(string $data): void {
    $offset = 4; // Skip auth type
    $mechanisms = [];
    
    // Parse available mechanisms
    while ($offset < strlen($data)) {
        $mechanism = '';
        while ($offset < strlen($data) && $data[$offset] !== "\0") {
            $mechanism .= $data[$offset++];
        }
        if ($mechanism) {
            $mechanisms[] = $mechanism;
        }
        $offset++; // Skip null terminator
    }
    
    if (in_array('SCRAM-SHA-256', $mechanisms)) {
        $this->performSCRAMSHA256Auth();
    } else {
        throw new Exception('No supported SASL mechanisms');
    }
}

private function performSCRAMSHA256Auth(): void {
    // SCRAM-SHA-256 Initial Response
    $clientNonce = base64_encode(random_bytes(18));
    $initialMessage = "n,,n=*,r=$clientNonce";
    
    $saslInitial = 'SCRAM-SHA-256' . "\0" . pack('N', strlen($initialMessage)) . $initialMessage;
    $message = new PGMessage('p', $saslInitial);
    fwrite($this->socket, $message->pack());
    
    // Continue SCRAM flow...
    $this->continueSCRAMFlow($clientNonce);
}

private function continueSCRAMFlow(string $clientNonce): void {
    // Read server first message
    $response = $this->readMessage();
    if ($response->type !== 'R') {
        throw new Exception('Expected authentication continue');
    }
    
    $authType = unpack('N', substr($response->data, 0, 4))[1];
    if ($authType !== 11) { // SASL Continue
        throw new Exception('Expected SASL continue');
    }
    
    $serverMessage = substr($response->data, 4);
    
    // Parse server message: r=<nonce>,s=<salt>,i=<iterations>
    $parts = [];
    foreach (explode(',', $serverMessage) as $part) {
        $kv = explode('=', $part, 2);
        $parts[$kv[0]] = $kv[1];
    }
    
    $serverNonce = $parts['r'];
    $salt = base64_decode($parts['s']);
    $iterations = (int)$parts['i'];
    
    // Generate client proof
    $clientFinalWithoutProof = "c=biws,r=$serverNonce";
    $authMessage = "n=*,r=$clientNonce,$serverMessage,$clientFinalWithoutProof";
    
    $saltedPassword = hash_pbkdf2('sha256', $this->pass, $salt, $iterations, 32, true);
    $clientKey = hash_hmac('sha256', 'Client Key', $saltedPassword, true);
    $storedKey = hash('sha256', $clientKey, true);
    $clientSignature = hash_hmac('sha256', $authMessage, $storedKey, true);
    $clientProof = $clientKey ^ $clientSignature;
    
    $clientFinal = $clientFinalWithoutProof . ',p=' . base64_encode($clientProof);
    
    $message = new PGMessage('p', $clientFinal);
    fwrite($this->socket, $message->pack());
}
```

**Resultado esperado:** Authentication OK (tipo 0)

---

## Passo 4: Ready for Query

### 4.1 Processar Parameter Status

```php
private function handleParameterStatus(string $data): void {
    $parts = explode("\0", $data, 2);
    if (count($parts) === 2) {
        $this->parameters[$parts[0]] = $parts[1];
    }
}

private function handleBackendKeyData(string $data): void {
    // Process ID e Secret Key para cancelamento de queries
    $processId = unpack('N', substr($data, 0, 4))[1];
    $secretKey = unpack('N', substr($data, 4, 4))[1];
    
    $this->parameters['process_id'] = $processId;
    $this->parameters['secret_key'] = $secretKey;
}
```

**Resultado esperado:** Mensagem 'Z' (Ready for Query) com status 'I' (Idle)

---

## Passo 5: Executar Queries

### 5.1 Simple Query Protocol

```php
public function query(string $sql): array {
    if (!$this->connected) {
        throw new Exception('Not connected');
    }
    
    $message = new PGMessage('Q', $sql . "\0");
    fwrite($this->socket, $message->pack());
    
    return $this->readQueryResults();
}

private function readQueryResults(): array {
    $results = [];
    $columns = [];
    
    while (true) {
        $message = $this->readMessage();
        
        switch ($message->type) {
            case 'T': // Row Description
                $columns = $this->parseRowDescription($message->data);
                break;
                
            case 'D': // Data Row
                $row = $this->parseDataRow($message->data, $columns);
                $results[] = $row;
                break;
                
            case 'C': // Command Complete
                $this->handleCommandComplete($message->data);
                break;
                
            case 'Z': // Ready for Query
                return $results;
                
            case 'E': // Error
                $this->handleError($message->data);
                break;
                
            case 'N': // Notice
                // Ignore notices for now
                break;
        }
    }
}
```

### 5.2 Parse Row Description

```php
private function parseRowDescription(string $data): array {
    $columns = [];
    $fieldCount = unpack('n', substr($data, 0, 2))[1];
    $offset = 2;
    
    for ($i = 0; $i < $fieldCount; $i++) {
        $column = [];
        
        // Field name
        $nameEnd = strpos($data, "\0", $offset);
        $column['name'] = substr($data, $offset, $nameEnd - $offset);
        $offset = $nameEnd + 1;
        
        // Table OID
        $column['table_oid'] = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        
        // Column attribute number
        $column['column_attr'] = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
        
        // Data type OID
        $column['type_oid'] = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        
        // Data type size
        $column['type_size'] = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
        
        // Type modifier
        $column['type_modifier'] = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        
        // Format code
        $column['format'] = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
        
        $columns[] = $column;
    }
    
    return $columns;
}
```

### 5.3 Parse Data Row

```php
private function parseDataRow(string $data, array $columns): array {
    $row = [];
    $fieldCount = unpack('n', substr($data, 0, 2))[1];
    $offset = 2;
    
    for ($i = 0; $i < $fieldCount; $i++) {
        $length = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        
        if ($length === 0xFFFFFFFF) { // NULL value
            $value = null;
        } else {
            $value = substr($data, $offset, $length);
            $offset += $length;
            
            // Convert based on type
            $value = $this->convertValue($value, $columns[$i]);
        }
        
        $row[$columns[$i]['name']] = $value;
    }
    
    return $row;
}

private function convertValue(string $value, array $column): mixed {
    switch ($column['type_oid']) {
        case 16: // BOOL
            return $value === 't';
            
        case 20: // INT8
        case 21: // INT2
        case 23: // INT4
            return (int)$value;
            
        case 700: // FLOAT4
        case 701: // FLOAT8
        case 1700: // NUMERIC
            return (float)$value;
            
        case 1114: // TIMESTAMP
        case 1184: // TIMESTAMPTZ
            return new DateTime($value);
            
        default: // String types
            return $value;
    }
}
```

---

## Implementação Completa

### Métodos Auxiliares

```php
private function readMessage(): PGMessage {
    // Read message type
    $type = fread($this->socket, 1);
    if ($type === false || $type === '') {
        throw new Exception('Connection lost');
    }
    
    // Read message length
    $lengthData = fread($this->socket, 4);
    if (strlen($lengthData) !== 4) {
        throw new Exception('Invalid message length');
    }
    
    $length = unpack('N', $lengthData)[1];
    $dataLength = $length - 4;
    
    // Read message data
    $data = '';
    while (strlen($data) < $dataLength) {
        $chunk = fread($this->socket, $dataLength - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new Exception('Connection lost');
        }
        $data .= $chunk;
    }
    
    return new PGMessage($type, $data);
}

private function handleError(string $data): void {
    $fields = [];
    $offset = 0;
    
    while ($offset < strlen($data)) {
        $fieldType = $data[$offset++];
        if ($fieldType === "\0") break;
        
        $valueEnd = strpos($data, "\0", $offset);
        $value = substr($data, $offset, $valueEnd - $offset);
        $fields[$fieldType] = $value;
        $offset = $valueEnd + 1;
    }
    
    $message = $fields['M'] ?? 'Unknown error';
    $code = $fields['C'] ?? '00000';
    
    throw new Exception("PostgreSQL Error [$code]: $message");
}

private function handleCommandComplete(string $data): void {
    $command = rtrim($data, "\0");
    // Parse command tag (e.g., "INSERT 0 1", "SELECT 5")
    $this->lastCommandTag = $command;
}
```

### Extended Query Protocol (Prepared Statements)

```php
public function prepare(string $name, string $sql): void {
    // Parse message
    $parseData = $name . "\0" . $sql . "\0" . pack('n', 0); // No parameter types
    $parseMessage = new PGMessage('P', $parseData);
    fwrite($this->socket, $parseMessage->pack());
    
    // Sync message
    $syncMessage = new PGMessage('S', '');
    fwrite($this->socket, $syncMessage->pack());
    
    $this->waitForReady();
}

public function execute(string $name, array $params = []): array {
    // Bind message
    $bindData = '' . "\0" . $name . "\0"; // Portal name + statement name
    $bindData .= pack('n', 0); // No parameter format codes
    $bindData .= pack('n', count($params)); // Parameter count
    
    foreach ($params as $param) {
        if ($param === null) {
            $bindData .= pack('N', 0xFFFFFFFF); // NULL
        } else {
            $paramStr = (string)$param;
            $bindData .= pack('N', strlen($paramStr)) . $paramStr;
        }
    }
    
    $bindData .= pack('n', 0); // No result format codes
    
    $bindMessage = new PGMessage('B', $bindData);
    fwrite($this->socket, $bindMessage->pack());
    
    // Execute message
    $executeData = '' . "\0" . pack('N', 0); // Portal name + max rows
    $executeMessage = new PGMessage('E', $executeData);
    fwrite($this->socket, $executeMessage->pack());
    
    // Sync message
    $syncMessage = new PGMessage('S', '');
    fwrite($this->socket, $syncMessage->pack());
    
    return $this->readQueryResults();
}

private function waitForReady(): void {
    while (true) {
        $message = $this->readMessage();
        
        switch ($message->type) {
            case 'Z': // Ready for Query
                return;
                
            case 'E': // Error
                $this->handleError($message->data);
                break;
                
            case '1': // Parse Complete
            case '2': // Bind Complete
            case '3': // Close Complete
                // Continue reading
                break;
        }
    }
}
```

### Exemplo de Uso

```php
$connector = new PostgreSQLConnector(
    'localhost',
    5432,
    'postgres',
    'password',
    'mydb'
);

$connector->connect();

// Simple query
$results = $connector->query("SELECT name, age FROM users WHERE active = true");
foreach ($results as $row) {
    echo "{$row['name']}: {$row['age']}\n";
}

// Prepared statement
$connector->prepare('get_user', 'SELECT * FROM users WHERE id = $1');
$user = $connector->execute('get_user', [123]);
```

---

## 🎯 Próximos Passos

1. **Implementar transações (BEGIN/COMMIT/ROLLBACK)**
2. **Suporte a COPY protocol**
3. **Notificações LISTEN/NOTIFY**
4. **SSL/TLS encryption**
5. **Connection pooling**
6. **Async queries**

---

## 📚 Referências

- [PostgreSQL Frontend/Backend Protocol](https://www.postgresql.org/docs/current/protocol.html)
- [PostgreSQL Message Formats](https://www.postgresql.org/docs/current/protocol-message-formats.html)
- [SCRAM-SHA-256 Authentication](https://tools.ietf.org/html/rfc7677)

---

**Autor:** Tutorial baseado na implementação do MySQLConnector  
**Data:** 2024  
**Versão:** 1.0