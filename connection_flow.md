# Fluxo Completo de Conexão SQL Server

## 1. TCP Three-Way Handshake

### Cliente (127.0.0.1:55199) → Servidor (127.0.0.1:1433)
**Pacote 1: SYN**
```
02000000 45000034 9a754000 80060000 7f000001 7f000001 d79f0599 b6ba1ccb
00000000 8002ffff c62b0000 0204ffd7 01030308 01010402
```

**Decodificação:**
- `02000000` - Loopback header (IPv4)
- `45` - IP version 4, header 20 bytes
- `06` - Protocol TCP
- `7f000001` - Source IP: 127.0.0.1
- `7f000001` - Dest IP: 127.0.0.1
- `d79f` - Source Port: 55199
- `0599` - Dest Port: 1433
- `b6ba1ccb` - Sequence Number
- `02` - Flag: SYN

### Servidor (127.0.0.1:1433) → Cliente (127.0.0.1:55199)
**Pacote 2: SYN-ACK**
```
[Servidor responde com SYN+ACK]
- Flags: SYN + ACK
- ACK Number: (Seq do cliente + 1)
```

### Cliente → Servidor
**Pacote 3: ACK**
```
[Cliente confirma com ACK]
- Flags: ACK
- Conexão TCP estabelecida ✓
```

---

## 2. Protocolo TDS - PRELOGIN

### Cliente → Servidor (porta 55199 → 1433)
**Pacote 4: TDS PRELOGIN**
```
12 01 00 22 00 00 00 00 00 00 08 00 06 01 00 0e 00 01 ff 00 00 00 00 00 00 02
```

**Decodificação:**
- `12` - Type: PRELOGIN
- `01` - Status: EOM (End of Message)
- `0022` - Length: 34 bytes (inclui header de 8 bytes)
- `0000` - SPID: 0
- `00` - Packet ID: 0
- `00` - Window: 0

**Opções PRELOGIN:**
- `00 0008 0006` - Token 0x00 (VERSION), offset 8, length 6
- `01 000e 0001` - Token 0x01 (ENCRYPTION), offset 14, length 1
- `ff` - Terminator

**Dados:**
- `000000000000` - Version: 0.0.0.0
- `02` - Encryption: ENCRYPT_NOT_SUP

### Servidor → Cliente (porta 1433 → 55199)
**Pacote 5: TDS PRELOGIN RESPONSE**
```
04 01 00 25 00 00 00 00 00 00 08 00 06 01 00 0e 00 01 ff 10 00 00 1a 00 06 01 00 20 00 01 02 00 21 00 01 ff 00 00 00 00 00 00 00
```

**Decodificação:**
- `04` - Type: RESPONSE
- `01` - Status: EOM
- `0025` - Length: 37 bytes

**Dados do Servidor:**
- Version: 16.0.0.6666 (exemplo)
- Encryption: ENCRYPT_OFF (0x00) ou ENCRYPT_ON (0x01)

---

## 3. TLS Handshake (se necessário)

### Se servidor requer TLS (ENCRYPT_ON ou ENCRYPT_REQ):

### Cliente → Servidor
**Pacote 6: TDS com TLS ClientHello encapsulado**
```
12 01 [tamanho] 00 00 00 00 [TLS ClientHello completo]
```

**TLS ClientHello começa com:**
- `16` - Content Type: Handshake
- `0303` - TLS Version 1.2
- `[random 32 bytes]`
- `[cipher suites]`

### Servidor → Cliente
**Pacote 7: TDS com TLS ServerHello encapsulado**
```
04 01 [tamanho] 00 00 00 00 [TLS ServerHello + Certificate + ServerHelloDone]
```

### Cliente → Servidor
**Pacote 8: TDS com TLS ClientKeyExchange**
```
12 01 [tamanho] 00 00 00 00 [TLS ClientKeyExchange + ChangeCipherSpec + Finished]
```

### Servidor → Cliente
**Pacote 9: TDS com TLS ChangeCipherSpec + Finished**
```
04 01 [tamanho] 00 00 00 00 [TLS ChangeCipherSpec + Finished]
```

**TLS estabelecido ✓** - Agora toda comunicação é criptografada

---

## 4. LOGIN7 (Autenticação)

### Cliente → Servidor
**Pacote 10: TDS LOGIN7** (criptografado se TLS ativo)
```
10 01 [tamanho] 00 00 00 00 [estrutura LOGIN7]
```

**Estrutura LOGIN7:**
- Length, TDS Version, Packet Size
- Client Program Version, Client PID
- Connection ID, Option Flags
- Time Zone, LCID
- Offsets: hostname, username, password, app name, server name, library name, language, database
- Dados: strings em UTF-16LE, senha com XOR

### Servidor → Cliente
**Pacote 11: LOGIN RESPONSE**
```
04 01 [tamanho] 00 00 00 00 [tokens de resposta]
```

**Tokens possíveis:**
- `0xAD` - LOGINACK (sucesso)
- `0xAA` - ERROR (falha)
- `0xE3` - ENVCHANGE (mudanças de ambiente)
- `0xFD` - DONE

---

## 5. Queries SQL

### Cliente → Servidor
**Pacote 12+: SQL BATCH**
```
01 01 [tamanho] 00 00 00 00 [query em UTF-16LE]
```

Exemplo: `SELECT @@VERSION`

### Servidor → Cliente
**Resposta com resultados**
```
04 01 [tamanho] 00 00 00 00 [tokens: COLMETADATA, ROW, DONE]
```

---

## Resumo do Fluxo

```
Cliente:55199 ──SYN──────────────────────→ Servidor:1433
Cliente:55199 ←─────────────────SYN+ACK─── Servidor:1433
Cliente:55199 ──ACK──────────────────────→ Servidor:1433
                [TCP Estabelecido]

Cliente:55199 ──PRELOGIN────────────────→ Servidor:1433
Cliente:55199 ←─────────PRELOGIN Response─ Servidor:1433

              [Se TLS necessário]
Cliente:55199 ──TLS ClientHello─────────→ Servidor:1433
Cliente:55199 ←─────TLS ServerHello + Cert Servidor:1433
Cliente:55199 ──TLS ClientKeyExchange───→ Servidor:1433
Cliente:55199 ←─────TLS Finished────────── Servidor:1433
                [TLS Estabelecido]

Cliente:55199 ──LOGIN7──────────────────→ Servidor:1433
Cliente:55199 ←─────────LOGINACK + INFO─── Servidor:1433
                [Autenticado]

Cliente:55199 ──SQL BATCH───────────────→ Servidor:1433
Cliente:55199 ←─────────RESULTS + DONE──── Servidor:1433
```
