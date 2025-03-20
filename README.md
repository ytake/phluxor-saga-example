# phluxor-saga-example / Money Transfer Saga

This example demonstrates how to implement a money transfer saga using Phluxor.

## Protocol Buffers 

```bash
$ protoc -I=./vendor/phluxor/phluxor/protobuf/ --proto_path=protobuf --php_out=src protobuf/*.proto
```

## Actor Hierarchy in Phluxor Saga Example

This diagram shows the actor hierarchy in the Phluxor Saga Example project, which implements the Saga pattern using an Actor model toolkit in PHP.

```mermaid
flowchart TD
    Root[Root Actor] --> Runner[Runner]
    
    %% First hierarchy - Account actors
    Runner --> Account1[FromAccount]
    Runner --> Account2[ToAccount]
    
    %% Second hierarchy - TransferProcess with Error Kernel Pattern
    Runner --> TP[TransferProcess]
    TP --> DA[DebitAttempt<br>AccountProxy]
    TP --> CA[CreditAttempt<br>AccountProxy]
    TP --> RD[RollbackDebit<br>AccountProxy]
    
    %% Error Kernel Pattern
    subgraph ErrorKernel1[Error Kernel - Runner]
        Runner
        style Runner fill:#f9f,stroke:#333,stroke-width:2px
    end
    
    subgraph ErrorKernel2[Error Kernel - TransferProcess]
        TP
        style TP fill:#f9f,stroke:#333,stroke-width:2px
    end
    
    %% Connections
    DA -.-> Account1
    CA -.-> Account2
    RD -.-> Account1
    
    %% Legend
    classDef actor fill:#ddf,stroke:#333,stroke-width:1px
    class Root,Account1,Account2,DA,CA,RD actor
    
    %% Notes
    note1[Error Kernel Pattern implemented<br>with OneForOneStrategy supervision]
    note1 -.-> ErrorKernel1
    note1 -.-> ErrorKernel2
    
    note2[Saga Pattern implemented through<br>TransferProcess state machine]
    note2 -.-> TP
```

## Explanation

1. **Root Actor**: The system's root actor that spawns the Runner actor.

2. **Runner**: Orchestrates the transfer process by creating Account actors and TransferProcess actors.

3. **Account Actors**: Represent bank accounts with balance operations (credit/debit).

4. **TransferProcess**: Implements the Saga pattern as a state machine with the following states:
    - Starting
    - Awaiting Debit Confirmation
    - Awaiting Credit Confirmation
    - Rolling Back Debit (compensation)

5. **AccountProxy Actors**: Mediate communication between TransferProcess and Account actors:
    - DebitAttempt: Attempts to debit the source account
    - CreditAttempt: Attempts to credit the destination account
    - RollbackDebit: Compensating action to rollback a debit if credit fails

6. **Error Kernel Pattern**: Implemented through supervision strategies:
    - Runner uses OneForOneStrategy to supervise TransferProcess actors
    - TransferProcess uses OneForOneStrategy to supervise AccountProxy actors
    - This creates a hierarchy where errors are contained and handled at appropriate levels

The system demonstrates a Saga pattern implementation where a distributed transaction (money transfer) is broken down into a sequence of local transactions with compensating actions for failure scenarios.
