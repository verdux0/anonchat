# Función `generate_secure_code`

```php
function generate_secure_code(PDO $pdo): string {
    // Array de dígitos para representar números en base 36:
    // 0-9 y luego A-Z (36 símbolos en total)
    $digits = ["0","1","2","3","4","5","6","7","8","9","A","B","C","D","E","F","G","H",
               "I","J","K","L","M","N","O","P","Q","R","S","T","U","V","W","X","Y","Z"];

    do {
        // Convierte la marca de tiempo actual (segundos desde 1970) a base36.
        // Esto aporta un componente dependiente del tiempo.
        $timePart = base36_from_int(time(), $digits);

        // Genera 4 bytes aleatorios criptográficamente seguros.
        // 'unpack("N", ...)' los interpreta como un entero sin signo de 32 bits en orden de red.
        // Luego se aplica una máscara 0x7fffffff para quedarse con 31 bits positivos.
        $randInt  = unpack('N', random_bytes(4))[1] & 0x7fffffff; // 31 bits

        // Convierte ese entero aleatorio a base36 usando el mismo conjunto de dígitos.
        $randPart = base36_from_int($randInt, $digits);

        // Combina la parte de tiempo y la parte aleatoria.
        // De este modo el código no es sólo aleatorio ni sólo el tiempo,
        // lo que reduce la predictibilidad.
        $code = $timePart . $randPart;

        // Prepara una consulta para comprobar si el código ya existe
        // en la tabla Conversation (columna Code).
        $stmt = $pdo->prepare('SELECT 1 FROM Conversation WHERE Code = ?');

        // Ejecuta la consulta pasando el código generado como parámetro.
        $stmt->execute([$code]);

        // El bucle se repetirá mientras fetchColumn() devuelva algún valor,
        // es decir, mientras el código ya exista en la base de datos.
    } while ($stmt->fetchColumn());

    // Cuando se sale del do/while significa que el código es único
    // (no se encontró en la tabla), y se devuelve.
    return $code;
}
```

## Descripción rápida

- Genera un código en **base 36** usando:
  - La hora actual (`time()`) → parte temporal.
  - Un entero aleatorio seguro (`random_bytes`) → parte aleatoria.
- Une ambas partes (`$timePart . $randPart`).
- Comprueba en BD (`Conversation.Code`) que no exista.
- Si existe, repite hasta obtener un código único.