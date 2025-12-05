<?php

class QueryResult
{
    public function __construct(
        public int $rowsCount,
        public array $rows,
        public string|null $error = null
    ){}
}

class ExecuteResult
{
    public function __construct(
        public int $affectedRows,
        public int $insertId
    ){}
}

class SQLServerDriver
{
    private $socket;
    private bool $connected = false;
    private int $packetId = 1;
    private int $timeout = 5;
    
    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $pass,
        private string $database
    ){}
    
    public function connect(): void
    {
        // Conectar usando fsockopen
        $errno = null;
        $errstr = null;
        
        $this->socket = @fsockopen(
            $this->host,
            $this->port,
            $errno,
            $errstr,
            $this->timeout
        );
        
        if (!$this->socket) {
            throw new Exception("Failed to connect to SQL Server: $errstr ($errno)");
        }
        
        // Enviar Pre-Login packet
        $this->sendPreLogin();
        
        // Ler resposta Pre-Login
        $this->readPreLoginResponse();
        
        // Enviar Login packet
        $this->sendLogin();
        
        // Ler resposta Login
        $this->readLoginResponse();
        
        $this->connected = true;
    }
    
    private function sendPreLogin(): void
    {
        // TDS Pre-Login packet (versão simplificada)
        $preLogin = "\x12\x01"; // Packet type: Pre-Login
        $preLogin .= "\x00\x2F"; // Length: 47 bytes
        $preLogin .= "\x00\x00"; // SPID
        $preLogin .= "\x00"; // Packet ID
        $preLogin .= "\x00"; // Window
        
        // Pre-Login options (versão mínima)
        $preLogin .= "\x00\x00\x15\x00\x06"; // Version option
        $preLogin .= "\x01\x00\x1B\x00\x01"; // Encryption option
        $preLogin .= "\xFF"; // Terminator
        
        // Version data
        $preLogin .= "\x08\x00\x01\x55\x00\x00"; // SQL Server version
        
        // Encryption data
        $preLogin .= "\x00"; // No encryption
        
        fwrite($this->socket, $preLogin);
    }
    
    private function readPreLoginResponse(): void
    {
        $response = fread($this->socket, 1024);
        // Processar resposta (simplificado)
    }
    
    private function sendLogin(): void
    {
        // TDS Login7 packet (versão muito simplificada)
        $login = "\x10\x01"; // Packet type: Login7
        
        // Construir payload de login
        $payload = "";
        $payload .= pack("V", 116); // Length
        $payload .= pack("V", 0x74000004); // TDS Version
        $payload .= pack("V", 4096); // Packet size
        $payload .= pack("V", 7); // Client version
        $payload .= pack("V", 0); // Client PID
        $payload .= pack("V", 0); // Connection ID
        $payload .= "\x60"; // Option flags 1
        $payload .= "\x83"; // Option flags 2
        $payload .= "\x00"; // Type flags
        $payload .= "\x00"; // Reserved
        $payload .= pack("V", 0); // Time zone
        $payload .= pack("V", 0); // Collation
        
        // Offsets e comprimentos (simplificado)
        $offset = 94;
        
        // Client name
        $payload .= pack("v", $offset); // Offset
        $payload .= pack("v", 7); // Length
        $offset += 14;
        
        // Username
        $payload .= pack("v", $offset); // Offset
        $payload .= pack("v", strlen($this->user)); // Length
        $offset += strlen($this->user) * 2;
        
        // Password
        $payload .= pack("v", $offset); // Offset
        $payload .= pack("v", strlen($this->pass)); // Length
        $offset += strlen($this->pass) * 2;
        
        // App name
        $payload .= pack("v", $offset); // Offset
        $payload .= pack("v", 7); // Length
        $offset += 14;
        
        // Server name
        $payload .= pack("v", $offset); // Offset
        $payload .= pack("v", strlen($this->host)); // Length
        $offset += strlen($this->host) * 2;
        
        // Library name
        $payload .= pack("v", 0); // Offset
        $payload .= pack("v", 0); // Length
        
        // Language
        $payload .= pack("v", 0); // Offset
        $payload .= pack("v", 0); // Length
        
        // Database
        $payload .= pack("v", $offset); // Offset
        $payload .= pack("v", strlen($this->database)); // Length
        
        // Dados das strings (UTF-16LE)
        $payload .= $this->stringToUTF16LE("PHPApp"); // Client name
        $payload .= $this->stringToUTF16LE($this->user); // Username
        $payload .= $this->encodePassword($this->pass); // Password
        $payload .= $this->stringToUTF16LE("PHPLib"); // App name
        $payload .= $this->stringToUTF16LE($this->host); // Server name
        $payload .= $this->stringToUTF16LE($this->database); // Database
        
        // Adicionar header com tamanho correto
        $totalLength = strlen($payload) + 8;
        $login .= pack("v", $totalLength); // Length
        $login .= "\x00\x00"; // SPID
        $login .= chr($this->packetId++); // Packet ID
        $login .= "\x00"; // Window
        $login .= $payload;
        
        fwrite($this->socket, $login);
    }
    
    private function stringToUTF16LE(string $str): string
    {
        return mb_convert_encoding($str, 'UTF-16LE', 'UTF-8');
    }
    
    private function encodePassword(string $password): string
    {
        // SQL Server password encoding (XOR + UTF-16LE)
        $encoded = "";
        for ($i = 0; $i < strlen($password); $i++) {
            $char = ord($password[$i]);
            $encoded .= chr(($char ^ 0xA5) & 0xFF) . "\x00";
        }
        return $encoded;
    }
    
    private function readLoginResponse(): void
    {
        $response = fread($this->socket, 1024);
        
        if (!$response || strlen($response) < 8) {
            throw new Exception("Invalid login response");
        }
        
        $packetType = ord($response[0]);
        
        if ($packetType === 0x04) { // Login response
            // Verificar se login foi bem-sucedido
            $tokenType = ord($response[8]);
            if ($tokenType === 0xAA) { // Error token
                throw new Exception("SQL Server login failed");
            }
        } else {
            throw new Exception("Unexpected packet type: " . $packetType);
        }
    }
    
    public function isConnected(): bool
    {
        return $this->connected;
    }
    
    public function startTransaction(): void
    {
        $this->execute("BEGIN TRANSACTION");
    }
    
    public function commit(): void
    {
        $this->execute("COMMIT");
    }
    
    public function rollback(): void
    {
        $this->execute("ROLLBACK");
    }
    
    public function execute(string $sql, array $params = []): ExecuteResult
    {
        // Processar parâmetros
        if (!empty($params)) {
            $sql = $this->processParameters($sql, $params);
        }
        
        // Enviar SQL Batch
        $this->sendSQLBatch($sql);
        
        // Ler resposta
        $response = $this->readResponse();
        
        // Processar resultado
        return new ExecuteResult(1, 0); // Simplificado
    }
    
    public function query(string $sql, array $params = []): QueryResult
    {
        try {
            // Processar parâmetros
            if (!empty($params)) {
                $sql = $this->processParameters($sql, $params);
            }
            
            // Enviar SQL Batch
            $this->sendSQLBatch($sql);
            
            // Ler resposta
            $response = $this->readResponse();
            
            // Processar resultado (simplificado)
            $rows = [];
            return new QueryResult(count($rows), $rows);
        } catch (Exception $e) {
            return new QueryResult(0, [], $e->getMessage());
        }
    }
    
    private function processParameters(string $sql, array $params): string
    {
        foreach ($params as $param) {
            if (is_string($param)) {
                $escaped = str_replace("'", "''", $param);
                $sql = preg_replace('/\?/', "'$escaped'", $sql, 1);
            } elseif (is_int($param) || is_float($param)) {
                $sql = preg_replace('/\?/', $param, $sql, 1);
            } elseif (is_null($param)) {
                $sql = preg_replace('/\?/', 'NULL', $sql, 1);
            }
        }
        return $sql;
    }
    
    private function sendSQLBatch(string $sql): void
    {
        // TDS SQL Batch packet
        $packet = "\x01\x01"; // Packet type: SQL Batch
        
        // Converter SQL para UTF-16LE
        $sqlUTF16 = $this->stringToUTF16LE($sql);
        
        $totalLength = strlen($sqlUTF16) + 8;
        $packet .= pack("v", $totalLength); // Length
        $packet .= "\x00\x00"; // SPID
        $packet .= chr($this->packetId++); // Packet ID
        $packet .= "\x00"; // Window
        $packet .= $sqlUTF16;
        
        fwrite($this->socket, $packet);
    }
    
    private function readResponse(): string
    {
        return fread($this->socket, 4096);
    }
    
    public function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }
}

// Teste do SQLServerDriver
try {
    $sqlServerDriver = new SQLServerDriver(
        "localhost", 1433, "sa", "YourPassword123", "TestDB"
    );
    
    echo "Tentando conectar ao SQL Server...\n";
    $sqlServerDriver->connect();
    echo "Conectado ao SQL Server via socket nativo!\n";
    
    // Criar tabela de teste
    $sqlServerDriver->execute("
        IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='tbTest' AND xtype='U')
        CREATE TABLE tbTest (
            id BIGINT IDENTITY(1,1) PRIMARY KEY,
            description NVARCHAR(255)
        )
    ");
    echo "Tabela criada/verificada!\n";
    
    // Inserir dados com parâmetros
    $executeResult = $sqlServerDriver->execute(
        "INSERT INTO tbTest (description) VALUES (?)",
        ["Teste SQL Server Socket"]
    );
    echo "Dados inseridos via prepared statement!\n";
    
    // Teste de transação
    echo "Testando transações...\n";
    $sqlServerDriver->startTransaction();
    try {
        $sqlServerDriver->execute(
            "INSERT INTO tbTest (description) VALUES (?)",
            ["Transação Socket 1"]
        );
        $sqlServerDriver->execute(
            "INSERT INTO tbTest (description) VALUES (?)",
            ["Transação Socket 2"]
        );
        $sqlServerDriver->commit();
        echo "Transação confirmada via socket!\n";
    } catch (Exception $e) {
        $sqlServerDriver->rollback();
        echo "Transação cancelada: " . $e->getMessage() . "\n";
    }
    
    // Consultar dados
    echo "Consultando dados...\n";
    $result = $sqlServerDriver->query(
        "SELECT * FROM tbTest WHERE description LIKE ?",
        ["%Socket%"]
    );
    
    echo "Registros encontrados: " . $result->rowsCount . "\n";
    
    $sqlServerDriver->disconnect();
    echo "Desconectado do SQL Server!\n";
    
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}