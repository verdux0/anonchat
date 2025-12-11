-- Poblar la base de datos anonchatTest con datos de ejemplo
USE anonchatTest;

INSERT INTO Admin (User, Password_Hash) VALUES
  ('admin', '$2y$12$examplehashadminxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'),
  ('moderator', '$2y$12$examplehashmodxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

-- Las contraseñas de las conversaciones coinciden con su Code
-- Los títulos son test1, test2, test3, ...
INSERT INTO Conversation (Code, Password_Hash, Status, Title, Description) VALUES
  ('CONV-001', '$2b$12$7Kv4vHbaCo/qaDiC5nLXHeq7L4I9du4x2NcoXZQOb6zuFWb4yyIJ.', 'active', 'test1', 'Conversación para dudas frecuentes'),
  ('CONV-002', 'CONV-002', 'pending', 'test2', 'Sugerencias de usuarios'),
  ('CONV-003', 'CONV-003', 'closed', 'test3', 'Reporte de problema resuelto'),
  ('CONV-004', 'CONV-004', 'active', 'test4', 'Acceso con contraseña'),
  ('CONV-005', 'CONV-005', 'waiting', 'test5', 'Esperando respuesta de admin');

INSERT INTO Messages (Conversation_ID, Sender, Content, File_Path) VALUES
  (1, 'user', 'Hola, necesito ayuda con la app.', NULL),
  (1, 'admin', 'Claro, ¿en qué puedo ayudarte?', NULL),
  (2, 'anonymous', 'Tengo una sugerencia para mejorar la UX.', NULL),
  (3, 'user', 'Ya se resolvió el problema, gracias.', NULL),
  (4, 'user', 'Accedí con contraseña, ¿hay alguien?', NULL),
  (4, 'admin', 'Sí, te escucho.', NULL),
  (5, 'anonymous', 'Sigo esperando respuesta.', NULL);