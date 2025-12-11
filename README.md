```markdown
# PHP CsvDb (Robust Flat-File Database)

A lightweight, robust, and concurrency-safe PHP CSV database class.  
Designed for small projects, logging systems, or prototyping that do not require MySQL/SQL databases.

## ✨ Features

* **Zero Dependencies**: No need to install any database server; only PHP standard libraries are required.
* **Concurrency Safe**: Built-in `flock` file locking mechanism to prevent conflicts during concurrent writes (race conditions).
* **Atomic Writes**: Uses a `temp file` + `rename` strategy to ensure data integrity even during power outages or crashes.
* **Sanitization**: Automatically prevents CSV injection and Null Byte attacks.
* **Auto-Trim**: Automatically trims invisible newline characters and spaces from field data, solving common CSV read bugs.
* **Standardized Response**: All CRUD operations return a unified format for easier logical judgment.

## 📦 Installation

Simply download `CsvDb.php` and include it in your project.

```php
require 'CsvDb.php';
```

## 🚀 Quick Start

### 1. Initialize the Database

If the file does not exist, the system will automatically create it and write the header row.

```php
// Define columns (system_id is automatically managed, no need to add manually)
$columns = ['name', 'email', 'status'];

// Instantiate
$db = new CsvDb('users.csv', $columns);
```

### 2. Insert Data (Insert)

`system_id` is automatically incremented.

```php
$data = [
    'name' => 'Allen',
    'email' => 'allen@example.com',
    'status' => 'active'
];

$result = $db->insert($data);

if ($result['success']) {
    echo "Inserted successfully, ID: " . $result['id'];
} else {
    echo "Error: " . $result['message'];
}
```

### 3. Query Data (Select)

Supports multi-condition filtering.

```php
// Query all users with status 'active'
$result = $db->select(['status' => 'active']);

if ($result['success']) {
    foreach ($result['data'] as $row) {
        echo $row['name'] . " - " . $row['email'] . "<br>";
    }
}
```

### 4. Update Data (Update)

Update data based on `system_id`.

```php
// Update the email of the user with ID 1
$updateData = ['email' => 'new_email@example.com'];

$result = $db->update(1, $updateData);

if ($result['success']) {
    echo "Update successful";
}
```

### 5. Delete Data (Delete)

Hard delete data based on `system_id`.

```php
$result = $db->delete(1);
```

### 6. Search by Keyword (Search)

Full-field fuzzy search.

```php
$result = $db->search('Allen');
// Any row containing "Allen" in any field (case-insensitive) will be retrieved.
```

## 📡 Response Structure

All methods (`insert`, `update`, `delete`, `select`, `search`) return a consistent array format:

```php
[
    'success' => true,      // Whether the operation was successful (bool)
    'message' => '...',     // Success or error message (string)
    'id'      => 101,       // Related ID (present in insert/update/delete)
    'data'    => [...]      // Data array (present in select/search)
]
```

## 🔒 Security Notes

The class includes the following protections:

* **BOM Header**: Automatically writes UTF-8 BOM to prevent Chinese character corruption when opening in Excel.
* **Excel Injection**: Automatically escapes fields starting with `=`, `+`, `-`, or `@`.
* **Null Byte**: Automatically removes `\0` from strings.

## 📜 License

MIT License
```
