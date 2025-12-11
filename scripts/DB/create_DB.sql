-- Core Tables for Anonymous Chat Database
CREATE DATABASE IF NOT EXISTS anonchatTest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE anonchatTest;
CREATE TABLE Conversation (
    ID BIGINT PRIMARY KEY AUTO_INCREMENT,
    Code VARCHAR(50) UNIQUE NOT NULL,
    Password_Hash VARCHAR(255) NULL,
    Created_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Updated_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    Status ENUM('pending','active','closed','waiting','archived') NOT NULL DEFAULT 'pending',
    Title VARCHAR(255) NULL,
    Description TEXT NULL
);

CREATE TABLE Admin (
    ID BIGINT PRIMARY KEY AUTO_INCREMENT,
    User VARCHAR(100) UNIQUE NOT NULL,
    Password_Hash VARCHAR(255) NOT NULL
);

CREATE TABLE Messages (
    ID BIGINT PRIMARY KEY AUTO_INCREMENT,
    Conversation_ID BIGINT NOT NULL,
    Sender ENUM('admin','user','anonymous') NOT NULL DEFAULT 'anonymous',
    Content TEXT NULL,
    File_Path VARCHAR(255) NULL,
    Created_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Conversation_ID) REFERENCES Conversation(ID) ON DELETE CASCADE
);

-- Indexes for efficient querying
CREATE INDEX idx_conversation_status ON Conversation(Status);
CREATE INDEX idx_messages_conversation ON Messages(Conversation_ID);
CREATE INDEX idx_messages_created_at ON Messages(Created_At);

-- Guidelines for future expansion:
-- 1. Add a Users table if you want to track identifiable users later.
-- 2. Introduce a ConversationParticipants table for multiple users per conversation.
-- 3. Add message status (read/unread) or reactions.
-- 4. Use partitioning or sharding for huge message volumes.
