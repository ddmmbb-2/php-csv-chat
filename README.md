
# PHP CsvDb2 (Robust & Secure Flat-File Database)

A lightweight, robust, and concurrency-safe PHP CSV database class. 
Designed for small projects, logging systems, or prototyping that do not require MySQL/SQL databases. **Now upgraded to V2 with advanced security and authentication features!**

## ✨ Features

* **Zero Dependencies**: No need to install any database server; only PHP standard libraries are required. Fully compatible with **PHP 7.x and 8.x**.
* **Concurrency Safe**: Built-in `flock` file locking mechanism to prevent conflicts during concurrent writes (race conditions).
* **Atomic Writes**: Uses a `temp file` + `rename` strategy to ensure data integrity even during power outages or crashes.
* **Built-in Security & XSS Protection**: Automatically sanitizes inputs (prevents CSV injection & Null Byte attacks) and escapes HTML entities on output to prevent Cross-Site Scripting (XSS).
* **Auto Password Hashing**: Automatically encrypts defined password fields using `password_hash()`.
* **Smart Output Filtering**: Password fields are automatically excluded from query results to prevent accidental data leaks.
* **Auto-Trim**: Automatically trims invisible newline characters and spaces from field data, solving common CSV read bugs.
* **Cross-Platform Safe**: Gracefully handles file permissions without throwing warnings on Windows (WAMP/XAMPP/IIS) while maintaining strict permissions on Unix/Linux.
* **Standardized Response**: All CRUD operations return a unified JSON-friendly format for easier logical judgment.

## 📦 Installation

Simply download `csvdb2.php` and include it in your project.

```php
require 'csvdb2.php';

```

## 🚀 Quick Start

### 1. Initialize the Database

If the file does not exist, the system will automatically create it and write the header row. You can also pass an `$options` array to configure security settings.

```php
// Define columns (system_id is automatically managed)
$columns = ['name', 'email', 'password', 'status'];

// Optional configurations (These are the default values)
$options = [
    'escape_html' => true,                // Escapes HTML tags on output (XSS protection)
    'password_fields' => ['password'],    // Fields to be automatically hashed
    'exclude_password_from_output' => true // Hides password hashes from select/search results
];

// Instantiate
$db = new CsvDb('users.csv', $columns,$options);

```

### 2. Insert Data (Insert)

`system_id` is automatically incremented. **Password fields are automatically hashed.**

```php
$data = [
    'name' => 'Allen',
    'email' => 'allen@example.com',
    'password' => 'secret123', // Will be hashed automatically!
    'status' => 'active'
];

$result = $db->insert($data);

if ($result['success']) {
    echo "Inserted successfully, ID: " . $result['id'];
}

```

### 3. Query Data (Select)

Supports multi-condition filtering. **Password hashes are hidden from the result by default.**

```php
// Query all users with status 'active'
$result =$db->select(['status' => 'active']);

if ($result['success']) {
    foreach ($result['data'] as$row) {
        // Output is automatically HTML-escaped safely
        echo $row['name'] . " - " . $row['email'] . "<br>";
    }
}

```

### 4. Authenticate User (Login)

Safely verify a user's password without manually fetching or comparing hashes.

```php
$credentials = ['email' => 'allen@example.com'];
$plainPassword = 'secret123';

$loginResult =$db->authenticate($credentials,$plainPassword);

if ($loginResult['success']) {
    echo "Login successful! Welcome, " . $loginResult['data']['name'];
    // $loginResult['data'] returns the user row (excluding the password field)
} else {
    echo "Login failed: " . $loginResult['message']; // e.g., 'Password incorrect' or 'User not found'
}

```

### 5. Update Data (Update)

Update data based on `system_id`. (If a password field is submitted as an empty string, it will be safely ignored).

```php
// Update the email of the user with ID 1
$updateData = ['email' => 'new_email@example.com'];

$result = $db->update(1,$updateData);

```

### 6. Delete Data (Delete)

Hard delete data based on `system_id`.

```php
$result =$db->delete(1);

```

### 7. Search by Keyword (Search)

Full-field fuzzy search. (Password hashes are excluded from the search scope to prevent accidental matches).

```php
$result =$db->search('Allen');
// Retrieves rows containing "Allen" in any text field (case-insensitive).

```

## 📡 Response Structure

All methods return a consistent array format:

```php
[
    'success' => true,      // Whether the operation was successful (bool)
    'message' => '...',     // Success or error message (string)
    'id'      => 101,       // Related ID (present in insert/update/delete)
    'data'    => [...]      // Data array (present in select/search/authenticate)
]

```

## 🔒 Security Notes

The class is heavily fortified with the following protections:

* **Password Protection**: Uses PHP's native robust `password_hash()` (Bcrypt).
* **XSS Mitigation**: Optional automatic `htmlspecialchars()` applied to all outputs.
* **BOM Header**: Automatically writes UTF-8 BOM to prevent string corruption when opening in MS Excel.
* **Excel CSV Injection**: Automatically escapes fields starting with `=`, `+`, `-`, or `@`.
* **Null Byte Removal**: Automatically strips `\0` from strings to prevent byte poisoning.
* **Strict Types Check**: Fully handles edge cases like `null` inputs to avoid deprecation warnings in PHP 8+.

## 📜 License

MIT License
