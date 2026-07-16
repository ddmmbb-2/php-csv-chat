<?php
/**
 * csvdb2.php
 * 最終完美版 (跨平台相容、含 XSS 防護、密碼加密與驗證、相容 PHP 7.x/8.x)
 *
 * 功能與特性：
 * 1. 標準化回應格式 (success, message, id, data)
 * 2. 自動 Trim 確保資料乾淨
 * 3. 自動 Sanitize（防 Excel 公式注入、Null Byte 安全漏洞）
 * 4. 原子寫入與檔案鎖，確保高併發時的資料完整性
 * 5. HTML 輸出轉義（防止 XSS 攻擊，可選）
 * 6. 密碼欄位自動加密 (Hash) & 登入驗證機制
 * 7. 查詢與搜尋結果自動過濾密碼欄位，避免資訊外洩
 * 8. 跨平台相容 (安全處理 Windows/Linux chmod 差異)
 */

class CsvDb {
    private $csvFile;
    private $lockFile;
    private $headers;
    private $primaryKey = 'system_id';

    // 設定屬性
    private $escapeHtml = true;                // 是否對輸出做 HTML 轉義
    private $passwordFields = ['password'];    // 要加密的密碼欄位
    private $excludePasswordFromOutput = true; // 查詢輸出是否移除密碼欄位

    /**
     * 建構子
     * @param string $filename CSV 檔案路徑
     * @param array  $columns  欄位名稱（system_id 會自動補在最前）
     * @param array  $options  額外選項
     */
    public function __construct($filename, $columns = [], $options = []) {
        $this->csvFile = $filename;
        $this->lockFile = $filename . '.lock';

        if (!in_array($this->primaryKey, $columns)) {
            array_unshift($columns, $this->primaryKey);
        }
        $this->headers = $columns;

        // 處理選項
        if (isset($options['escape_html'])) {
            $this->escapeHtml = (bool)$options['escape_html'];
        }
        if (isset($options['password_fields'])) {
            $this->passwordFields = (array)$options['password_fields'];
        }
        if (isset($options['exclude_password_from_output'])) {
            $this->excludePasswordFromOutput = (bool)$options['exclude_password_from_output'];
        }

        $this->init();
    }

    // ================= 公開設定方法 =================

    public function setEscapeHtml($flag) {
        $this->escapeHtml = (bool)$flag;
    }

    public function setPasswordFields(array $fields) {
        $this->passwordFields = $fields;
    }

    public function setExcludePasswordFromOutput($flag) {
        $this->excludePasswordFromOutput = (bool)$flag;
    }

    // ================= 初始化、檔案處理 =================

    /**
     * 安全的改變檔案權限 (跨平台相容)
     * Windows 系統下直接忽略，避免拋出 Warning 破壞 JSON 回應
     */
    private function safeChmod($filename, $mode) {
        if (DIRECTORY_SEPARATOR === '/') {
            @chmod($filename, $mode);
        }
    }

    private function init() {
        if (!file_exists($this->lockFile)) {
            touch($this->lockFile);
            $this->safeChmod($this->lockFile, 0666);
        }
        if (!file_exists($this->csvFile)) {
            $f = fopen($this->csvFile, 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $this->headers);
            fclose($f);
            $this->safeChmod($this->csvFile, 0666);
        } else {
            $f = fopen($this->csvFile, 'r');
            $bom = fread($f, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($f);
            $existingHeaders = fgetcsv($f);
            fclose($f);
            
            if ($existingHeaders) {
                $existingHeaders = array_map('trim', $existingHeaders);
                // 優化：如果現有 CSV 缺少主鍵，自動補上，避免後續 CRUD 錯亂
                if (!in_array($this->primaryKey, $existingHeaders)) {
                    array_unshift($existingHeaders, $this->primaryKey);
                }
                $this->headers = $existingHeaders;
            }
        }
    }

    private function response($success, $message, $id = null, $data = null) {
        return [
            'success' => $success,
            'message' => $message,
            'id'      => $id,
            'data'    => $data
        ];
    }

    /**
     * 基本清洗：NULL Byte、Excel 公式注入
     */
    private function sanitize($data) {
        $cleanData = [];
        foreach ($data as $key => $value) {
            $val = (string)$value;
            $val = str_replace("\0", "", $val);
            if (preg_match('/^[=\+\-@]/', $val)) {
                $val = "'" . $val;
            }
            $cleanData[$key] = $val;
        }
        return $cleanData;
    }

    /**
     * 密碼加密處理
     * 優化：使用 strlen 處理 Null 值，相容 PHP 7.x/8.x 嚴格型別
     */
    private function encryptPasswords(array &$data, $isUpdate = false) {
        foreach ($this->passwordFields as $field) {
            if (array_key_exists($field, $data)) {
                if (strlen((string)$data[$field]) > 0) {
                    $data[$field] = password_hash((string)$data[$field], PASSWORD_DEFAULT);
                } else {
                    // 更新時密碼為空則不修改該欄位
                    if ($isUpdate) {
                        unset($data[$field]);
                    }
                }
            }
        }
    }

    /**
     * HTML 轉義單行
     */
    private function escapeRow($row) {
        return array_map(function($val) {
            return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
        }, $row);
    }

    /**
     * 輸出前過濾：移除密碼欄位 + HTML 轉義 (依設定)
     */
    private function filterOutput($rows) {
        if ($this->excludePasswordFromOutput) {
            foreach ($rows as &$row) {
                foreach ($this->passwordFields as $pf) {
                    unset($row[$pf]);
                }
            }
        }
        if ($this->escapeHtml) {
            foreach ($rows as &$row) {
                $row = $this->escapeRow($row);
            }
        }
        return $rows;
    }

    private function process($callback) {
        // 優化：改用 c+ 模式，若檔案不存在自動建立，防錯性更高
        $fp = fopen($this->lockFile, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            try {
                $rows = $this->readRaw();
                $result = $callback($rows);
                if (isset($result['save']) && $result['save'] === true) {
                    $this->writeRaw($result['data']);
                }
                flock($fp, LOCK_UN);
                fclose($fp);
                return isset($result['result']) ? $result['result'] : $this->response(true, 'Operation completed');
            } catch (Exception $e) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return $this->response(false, 'Error: ' . $e->getMessage());
            }
        } else {
            if ($fp) fclose($fp);
            return $this->response(false, 'System busy: Cannot lock database');
        }
    }

    private function readRaw() {
        $rows = [];
        if (($f = fopen($this->csvFile, 'r')) !== false) {
            $bom = fread($f, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($f);
            $headers = fgetcsv($f);
            if ($headers) {
                $headers = array_map('trim', $headers);
                while (($data = fgetcsv($f)) !== false) {
                    if (count($data) == count($headers)) {
                        $data = array_map('trim', $data);
                        $rows[] = array_combine($headers, $data);
                    }
                }
            }
            fclose($f);
        }
        return $rows;
    }

    private function writeRaw($rows) {
        $tempFile = tempnam(dirname($this->csvFile), 'csv_tmp_');
        $f = fopen($tempFile, 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, $this->headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($this->headers as $h) {
                $line[] = isset($row[$h]) ? $row[$h] : '';
            }
            fputcsv($f, $line);
        }
        fclose($f);
        if (!rename($tempFile, $this->csvFile)) {
            copy($tempFile, $this->csvFile);
            unlink($tempFile);
        }
        $this->safeChmod($this->csvFile, 0666);
    }

    private function nextId($rows) {
        $ids = array_column($rows, $this->primaryKey);
        $ids = array_filter($ids, 'is_numeric');
        return $ids ? max($ids) + 1 : 1;
    }

    // ================= CRUD 方法 =================

    public function select($filters = []) {
        $fp = fopen($this->lockFile, 'r');
        if ($fp && flock($fp, LOCK_SH)) {
            try {
                $rows = $this->readRaw();
                flock($fp, LOCK_UN);
                fclose($fp);

                if (!empty($filters)) {
                    $rows = array_filter($rows, function($row) use ($filters) {
                        foreach ($filters as $k => $v) {
                            if (!isset($row[$k]) || $row[$k] != $v) return false;
                        }
                        return true;
                    });
                    $rows = array_values($rows);
                }

                // 輸出前過濾（移除密碼、轉義 HTML）
                $rows = $this->filterOutput($rows);

                return $this->response(true, '查詢成功', null, $rows);
            } catch (Exception $e) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return $this->response(false, '讀取錯誤: ' . $e->getMessage(), null, []);
            }
        } else {
            if ($fp) fclose($fp);
            return $this->response(false, '系統忙碌中 (無法讀取)', null, []);
        }
    }

    public function search($keyword) {
        $fp = fopen($this->lockFile, 'r');
        if ($fp && flock($fp, LOCK_SH)) {
            try {
                $rows = $this->readRaw();
                flock($fp, LOCK_UN);
                fclose($fp);

                if (empty($keyword)) {
                    $rows = $this->filterOutput($rows);
                    return $this->response(true, '關鍵字為空，回傳全部', null, $rows);
                }

                $keyword = strtolower($keyword);
                $rows = array_filter($rows, function($row) use ($keyword) {
                    foreach ($row as $k => $val) {
                        // 優化：搜尋時略過密碼欄位，避免意外比對到 Hash 值
                        if (in_array($k, $this->passwordFields)) continue;

                        if (strpos(strtolower((string)$val), $keyword) !== false) return true;
                    }
                    return false;
                });
                $rows = array_values($rows);
                $rows = $this->filterOutput($rows);

                return $this->response(true, "搜尋成功，找到 " . count($rows) . " 筆", null, $rows);
            } catch (Exception $e) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return $this->response(false, '搜尋錯誤: ' . $e->getMessage(), null, []);
            }
        } else {
            if ($fp) fclose($fp);
            return $this->response(false, '系統忙碌中', null, []);
        }
    }

    public function insert($data) {
        $data = $this->sanitize($data);
        $this->encryptPasswords($data, false); // 新增時加密密碼

        return $this->process(function($rows) use ($data) {
            $newId = $this->nextId($rows);
            $newRow = [$this->primaryKey => (string)$newId];
            foreach ($this->headers as $h) {
                if ($h !== $this->primaryKey) {
                    $newRow[$h] = isset($data[$h]) ? $data[$h] : '';
                }
            }
            $rows[] = $newRow;
            return [
                'save'   => true,
                'data'   => $rows,
                'result' => $this->response(true, '新增成功', $newId, $newRow)
            ];
        });
    }

    public function update($id, $data) {
        $data = $this->sanitize($data);
        $this->encryptPasswords($data, true); // 更新時加密（空密碼不更新）

        return $this->process(function($rows) use ($id, $data) {
            $found = false;
            foreach ($rows as &$row) {
                if ($row[$this->primaryKey] == $id) {
                    foreach ($data as $k => $v) {
                        if (in_array($k, $this->headers) && $k !== $this->primaryKey) {
                            $row[$k] = $v;
                        }
                    }
                    $found = true;
                    break;
                }
            }
            if ($found) {
                return [
                    'save'   => true,
                    'data'   => $rows,
                    'result' => $this->response(true, '更新成功', $id)
                ];
            }
            return [
                'save'   => false,
                'result' => $this->response(false, '找不到該 ID', $id)
            ];
        });
    }

    public function delete($id) {
        return $this->process(function($rows) use ($id) {
            $originalCount = count($rows);
            $rows = array_filter($rows, function($row) use ($id) {
                return $row[$this->primaryKey] != $id;
            });
            if (count($rows) < $originalCount) {
                return [
                    'save'   => true,
                    'data'   => $rows,
                    'result' => $this->response(true, '刪除成功', $id)
                ];
            }
            return [
                'save'   => false,
                'result' => $this->response(false, '刪除失敗：找不到該 ID', $id)
            ];
        });
    }

    // ================= 登入驗證 =================

    /**
     * 驗證使用者密碼
     *
     * @param array  $criteria      查詢條件，例: ['username' => 'john']
     * @param string $password      明文密碼
     * @param string $passwordField 要驗證的密碼欄位名 (預設使用 $this->passwordFields 的第一個)
     * @return array 標準化回應，成功時 data 為不含密碼的用戶資料 (已轉義)
     */
    public function authenticate(array $criteria, string $password, string $passwordField = null) {
        if ($passwordField === null) {
            $passwordField = !empty($this->passwordFields) ? $this->passwordFields[0] : 'password';
        }

        $fp = fopen($this->lockFile, 'r');
        if ($fp && flock($fp, LOCK_SH)) {
            try {
                $rows = $this->readRaw();
                flock($fp, LOCK_UN);
                fclose($fp);

                // 根據條件尋找使用者
                $user = null;
                foreach ($rows as $row) {
                    $match = true;
                    foreach ($criteria as $k => $v) {
                        if (!isset($row[$k]) || $row[$k] != $v) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $user = $row;
                        break;
                    }
                }

                if (!$user) {
                    return $this->response(false, '用戶不存在');
                }
                if (!isset($user[$passwordField]) || !is_string($user[$passwordField])) {
    return $this->response(false, '密碼欄位資料異常');
}
if (!password_verify($password, $user[$passwordField])) {
    return $this->response(false, '密碼錯誤');
}

                // 驗證成功：移除所有密碼欄位
                foreach ($this->passwordFields as $pf) {
                    unset($user[$pf]);
                }
                // 若啟用 HTML 轉義，對輸出處理
                if ($this->escapeHtml) {
                    $user = $this->escapeRow($user);
                }

                return $this->response(true, '驗證成功', $user[$this->primaryKey] ?? null, $user);

            } catch (Exception $e) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return $this->response(false, '驗證錯誤: ' . $e->getMessage());
            }
        } else {
            if ($fp) fclose($fp);
            return $this->response(false, '系統忙碌中');
        }
    }
}
?>
