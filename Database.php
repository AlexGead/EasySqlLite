<?php
// Author Perchik Aleksandr
class Database {

    private $db;
    private $filename;
    private $logFile;

    /**
     * Конструктор класса.
     *
     * @param string $filename Путь к файлу базы данных SQLite.
     * @param string|null $logFile Путь к файлу для логирования ошибок (необязательно).
     */
    public function __construct(string $filename, string $logFile = null) {
        $this->filename = $filename;
        $this->logFile = $logFile;
        $this->connect();
    }

    /**
     * Подключается к базе данных SQLite.
     *
     * @return bool Возвращает true при успешном подключении, false - в противном случае.
     */
    private function connect(): bool {
        try {
            $this->db = new PDO("sqlite:" . $this->filename);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return true;
        } catch (PDOException $e) {
            $this->logError("Ошибка подключения к базе данных: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Создает таблицу в базе данных.
     *
     * @param string $tableName Имя таблицы.
     * @param array $columns   Ассоциативный массив, где ключ - имя столбца, значение - тип данных SQL.
     *                         Пример: ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'name' => 'TEXT', 'age' => 'INTEGER']
     *
     * @return bool Возвращает true при успешном создании таблицы, false - в противном случае.
     */
    public function createTable(string $tableName, array $columns): bool {
        $sql = "CREATE TABLE IF NOT EXISTS " . $tableName . " (";
        $columnDefinitions = [];
        foreach ($columns as $columnName => $columnType) {
            $columnDefinitions[] = $columnName . " " . $columnType;
        }
        $sql .= implode(", ", $columnDefinitions);
        $sql .= ")";

        try {
            $this->db->exec($sql);
            return true;
        } catch (PDOException $e) {
            $this->logError("Ошибка при создании таблицы '" . $tableName . "': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Вставляет данные в таблицу.
     *
     * @param string $tableName Имя таблицы.
     * @param array $data      Ассоциативный массив, где ключ - имя столбца, значение - значение для вставки.
     * @param array $validationRules  Ассоциативный массив правил валидации (необязательно).
     *
     * @return bool Возвращает true при успешной вставке, false - в противном случае.
     */
    public function set(string $tableName, array $data, array $validationRules = []): bool {
        if (!$this->validateData($data, $validationRules)) {
            return false; // Валидация не пройдена
        }

        $columns = implode(", ", array_keys($data));
        $placeholders = ":" . implode(", :", array_keys($data));
        $sql = "INSERT INTO " . $tableName . " (" . $columns . ") VALUES (" . $placeholders . ")";

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($data as $column => $value) {
                $stmt->bindValue(":" . $column, $value);
            }
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            $this->logError("Ошибка при вставке данных в таблицу '" . $tableName . "': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Обновляет данные в таблице.
     *
     * @param string $tableName Имя таблицы.
     * @param array $data      Ассоциативный массив, где ключ - имя столбца, значение - новое значение.
     * @param string $where     Условие WHERE для обновления записей.
     * @param array $bindings  Массив значений для подстановки в условие WHERE (для безопасности).
     * @param array $validationRules  Ассоциативный массив правил валидации (необязательно).
     *
     * @return bool Возвращает true при успешном обновлении, false - в противном случае.
     */
    public function update(string $tableName, array $data, string $where, array $bindings = [], array $validationRules = []): bool {
        if (!$this->validateData($data, $validationRules)) {
            return false; // Валидация не пройдена
        }

        $setClauses = [];
        foreach ($data as $column => $value) {
            $setClauses[] = $column . " = :" . $column;
        }
        $sql = "UPDATE " . $tableName . " SET " . implode(", ", $setClauses) . " WHERE " . $where;

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($data as $column => $value) {
                $stmt->bindValue(":" . $column, $value);
            }
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            $this->logError("Ошибка при обновлении данных в таблице '" . $tableName . "': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Удаляет данные из таблицы.
     *
     * @param string $tableName Имя таблицы.
     * @param string $where     Условие WHERE для удаления записей.
     * @param array $bindings  Массив значений для подстановки в условие WHERE (для безопасности).
     *
     * @return bool Возвращает true при успешном удалении, false - в противном случае.
     */
    public function delete(string $tableName, string $where, array $bindings = []): bool {
        $sql = "DELETE FROM " . $tableName . " WHERE " . $where;

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            $this->logError("Ошибка при удалении данных из таблицы '" . $tableName . "': " . $e->getMessage());
            return false;
        }
    }

      /**
     * Выбирает данные из таблицы.
     *
     * @param string $tableName Имя таблицы.
     * @param string $where     Условие WHERE (необязательно).
     * @param array  $bindings  Массив значений для подстановки в условие WHERE (для безопасности).
     * @param string $columns   Список столбцов для выбора (по умолчанию '*').
     * @param string|null $orderBy Условие сортировки ORDER BY (необязательно).
     * @param int|null $limit Ограничение количества возвращаемых записей (необязательно).
     *
     * @return array|false Возвращает массив с результатами или false в случае ошибки.
     *
     * @return array Возвращает массив с результатами или false в случае ошибки.
     */
    public function get(string $tableName, string $where = null, array $bindings = [], string $columns = '*', string $orderBy = null, int $limit = null)
    {
        $sql = "SELECT " . $columns . " FROM " . $tableName;

        if ($where) {
            $sql .= " WHERE " . $where;
        }

        if ($orderBy) {
            $sql .= " ORDER BY " . $orderBy;
        }

        if ($limit) {
            $sql .= " LIMIT " . $limit;
        }

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $result === false ? false : $result;
        } catch (PDOException $e) {
            $this->logError("Ошибка при выборке данных из таблицы '" . $tableName . "': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Начинает транзакцию.
     *
     * @return bool Возвращает true в случае успеха, false в случае ошибки.
     */
    public function beginTransaction(): bool {
        try {
            $this->db->beginTransaction();
            return true;
        } catch (PDOException $e) {
            $this->logError("Ошибка при начале транзакции: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Фиксирует транзакцию.
     *
     * @return bool Возвращает true в случае успеха, false в случае ошибки.
     */
    public function commitTransaction(): bool {
        try {
            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->logError("Ошибка при фиксации транзакции: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Откатывает транзакцию.
     *
     * @return bool Возвращает true в случае успеха, false в случае ошибки.
     */
    public function rollbackTransaction(): bool {
        try {
            $this->db->rollBack();
            return true;
        } catch (PDOException $e) {
            $this->logError("Ошибка при откате транзакции: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Удаляет таблицу.
     *
     * @param string $tableName Имя таблицы для удаления.
     * @return bool Возвращает true в случае успеха, false в случае ошибки.
     */
    public function dropTable(string $tableName): bool {
        $sql = "DROP TABLE IF EXISTS " . $tableName;
        try {
            $this->db->exec($sql);
            return true;
        } catch (PDOException $e) {
            $this->logError("Ошибка при удалении таблицы '" . $tableName . "': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Валидирует данные перед вставкой или обновлением.
     *
     * @param array $data Данные для валидации.
     * @param array $rules Правила валидации (ассоциативный массив).
     *                     Пример: ['name' => 'required|string', 'age' => 'integer|min:0']
     * @return bool Возвращает true, если данные валидны, false в противном случае.
     */
    private function validateData(array $data, array $rules): bool {
        foreach ($rules as $field => $ruleString) {
            $rulesArray = explode('|', $ruleString);
            foreach ($rulesArray as $rule) {
                $parts = explode(':', $rule);
                $ruleName = $parts[0];
                $ruleValue = $parts[1] ?? null;

                if (!isset($data[$field]) && $ruleName === 'required') {
                    $this->logError("Поле '" . $field . "' обязательно для заполнения.");
                    return false;
                }

                if (isset($data[$field])) {
                    switch ($ruleName) {
                        case 'string':
                            if (!is_string($data[$field])) {
                                $this->logError("Поле '" . $field . "' должно быть строкой.");
                                return false;
                            }
                            break;
                        case 'integer':
                            if (!is_int($data[$field]) && !ctype_digit(strval($data[$field]))) {
                                $this->logError("Поле '" . $field . "' должно быть целым числом.");
                                return false;
                            }
                            break;
                        case 'float':
                            if (!is_float($data[$field]) && !is_numeric($data[$field])) {
                                $this->logError("Поле '" . $field . "' должно быть числом с плавающей точкой.");
                                return false;
                            }
                            break;
                        case 'min':
                            if (is_numeric($data[$field]) && $data[$field] < $ruleValue) {
                                $this->logError("Поле '" . $field . "' должно быть не меньше " . $ruleValue . ".");
                                return false;
                            }
                            break;
                        case 'max':
                            if (is_numeric($data[$field]) && $data[$field] > $ruleValue) {
                                $this->logError("Поле '" . $field . "' должно быть не больше " . $ruleValue . ".");
                                return false;
                            }
                            break;
                        case 'email':
                            if (!filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
                                $this->logError("Поле '" . $field . "' должно быть валидным email адресом.");
                                return false;
                            }
                            break;
                        case 'regex':
                            if (!preg_match($ruleValue, $data[$field])) {
                                $this->logError("Поле '" . $field . "' не соответствует регулярному выражению: " . $ruleValue);
                                return false;
                            }
                            break;
                        // Добавьте другие правила валидации по мере необходимости
                    }
                }
            }
        }
        return true;
    }

    /**
     * Записывает сообщение об ошибке в лог-файл.
     *
     * @param string $message Сообщение об ошибке.
     */
    private function logError(string $message): void {
        if ($this->logFile) {
            $timestamp = date("Y-m-d H:i:s");
            error_log("[$timestamp] " . $message . PHP_EOL, 3, $this->logFile);
        } else {
            error_log($message . PHP_EOL); // Вывод в стандартный лог ошибок PHP
        }
    }

    /**
     * Закрывает соединение с базой данных.
     */
    public function disconnect(): void {
        $this->db = null;
    }
}

?>