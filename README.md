# WHMCS_SmilePay
這是一個專為 SmilePay (速買配) 所設計的 WHMCS 的金流模組。採用背景取號，繳費資訊會一同存放於 WHMCS 資料庫當中，並自動創建資料表，不會使單一帳單且單一繳費方式多次重複取號。

遇到任何錯誤或問題歡迎直接私訊我或發 Issue，也歡迎透過 PR 加強功能。
## 測試環境
以下是經過開發測試的版本，其他版本未經測試，但也可能能用。
* WHMCS 8.10.1／PHP 8.1.28
## 支援的收費方式
* 銀行轉帳
* 711 ibon／萊爾富LifeET 代碼
* 全家 FamiPort／萊爾富LifeET 代碼
* 四大便利超商條碼
## 注意事項
採用背景取號方式取得繳費連結會使 SmilePay 後台的「消費者IP位置」顯示為 WHMCS 網頁主機所發送請求的 IP，而非消費者 IP。
## 安裝方式
請將需要的收費方式程式文件放入 WHMCS 系統的 /modules/gateways 資料夾當中，也一併放入 Callback 文件。

啟用完成金流模組後後，請務必在後台填寫相關參數，所有欄位都是必填，留空或填寫錯誤，會導致模組無法正常使用。

另外驗證參數也請務必設定，確保回傳資料接收正確。
## 使用聲明
WHMCS_SmilePay 是基於 [MIT License](https://github.com/FanYueee/WHMCS_SmilePay/blob/main/LICENSE) 所發布。

在轉發或重新發布時也請留下原作者名稱。

