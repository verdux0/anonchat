-- Mostrar todas las tablas con datos
USE anonchatTest;

SELECT * FROM Admin ORDER BY ID;

SELECT * FROM Conversation ORDER BY ID;

SELECT * FROM Messages ORDER BY ID;

-- Vista combinada de mensajes con info de conversación
SELECT
  m.ID               AS message_id,
  c.Code             AS conversation_code,
  c.Status           AS conversation_status,
  m.Sender,
  m.Content,
  m.File_Path,
  m.Created_At
FROM Messages m
JOIN Conversation c ON c.ID = m.Conversation_ID
ORDER BY m.ID;