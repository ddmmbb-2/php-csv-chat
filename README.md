````markdown
# PHP CsvDb (Robust Flat-File Database)

一個輕量、穩健且具備併發保護的 PHP CSV 資料庫類別。
專為不需要 MySQL/SQL 資料庫的小型專案、日誌系統或原型開發設計。

## ✨ 特色 (Features)

* **零依賴 (Zero Dependencies)**：不需要安裝任何資料庫伺服器，只需 PHP 標準函式庫。
* **併發安全 (Concurrency Safe)**：內建 `flock` 檔案鎖定機制，防止多人同時寫入時的衝突 (Race Conditions)。
* **原子寫入 (Atomic Writes)**：使用 `temp file` + `rename` 策略，確保在斷電或崩潰時不會損壞原始資料。
* **資料清洗 (Sanitization)**：自動防範 Excel 公式注入 (CSV Injection) 與 Null Byte 攻擊。
* **自動修剪 (Auto-Trim)**：自動清除欄位資料前後的隱形換行符號與空白，解決 CSV 常見的讀取 Bug。
* **標準化回應 (Standardized Response)**：所有 CRUD 操作皆回傳統一格式，方便邏輯判斷。

## 📦 安裝 (Installation)

只需下載 `CsvDb.php` 並引入您的專案即可。

```php
require 'CsvDb.php';
````

## 🚀 快速開始 (Quick Start)

### 1\. 初始化資料庫

如果檔案不存在，系統會自動建立並寫入標題列。

```php
// 定義欄位 (系統會自動管理 system_id，不需要手動加入)
$columns = ['name', 'email', 'status'];

// 實例化
$db = new CsvDb('users.csv', $columns);
```

### 2\. 新增資料 (Insert)

`system_id` 會自動遞增生成。

```php
$data = [
    'name' => 'Allen',
    'email' => 'allen@example.com',
    'status' => 'active'
];

$result = $db->insert($data);

if ($result['success']) {
    echo "新增成功，ID: " . $result['id'];
} else {
    echo "錯誤: " . $result['message'];
}
```

### 3\. 查詢資料 (Select)

支援多條件篩選。

```php
// 查詢所有 status 為 active 的用戶
$result = $db->select(['status' => 'active']);

if ($result['success']) {
    foreach ($result['data'] as $row) {
        echo $row['name'] . " - " . $row['email'] . "<br>";
    }
}
```

### 4\. 更新資料 (Update)

根據 `system_id` 更新資料。

```php
// 將 ID 為 1 的用戶 email 更新
$updateData = ['email' => 'new_email@example.com'];

$result = $db->update(1, $updateData);

if ($result['success']) {
    echo "更新成功";
}
```

### 5\. 刪除資料 (Delete)

根據 `system_id` 刪除資料 (硬刪除)。

```php
$result = $db->delete(1);
```

### 6\. 關鍵字搜尋 (Search)

全欄位模糊搜尋。

```php
$result = $db->search('Allen');
// 只要任何欄位包含 "Allen" (不分大小寫) 都會被抓出來
```

## 📡 回應結構 (Response Structure)

所有方法 (`insert`, `update`, `delete`, `select`, `search`) 都回傳一致的陣列格式：

```php
[
    'success' => true,      // 操作是否成功 (bool)
    'message' => '...',     // 成功或錯誤訊息 (string)
    'id'      => 101,       // 相關 ID (新增/更新/刪除時有值)
    'data'    => [...]      // 資料陣列 (查詢/搜尋時有值)
]
```

## 🔒 安全性說明

本類別已內建以下防護：

  * **BOM Header**：自動寫入 UTF-8 BOM，防止 Excel 開啟時中文亂碼。
  * **Excel Injection**：如果欄位以 `=`, `+`, `-`, `@` 開頭，會自動跳脫處理。
  * **Null Byte**：自動移除字串中的 `\0`。

## 📜 License

MIT License

```
```