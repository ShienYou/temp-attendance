📦 Taipei Revival Attendance System

實作交接說明（V1.0 → V1.1 → V1.2）【V1.2 更新版】

本文件目的：
讓 V1.3 小玲只看 SSOT v1.5 + 你給的最新版 zip + 本說明，就能無縫接手繼續實作，不需回頭翻對話。

一、目前專案狀態總覽（一句話）

核心資料表 + Core Service（sessions / people / attendance）已具備可用骨架與主要實作。
但目前前台 Leader 測試流程「能載入、不能寫入」，卡在 AJAX 錯誤處理策略不一致 + newcomers/db wrapper 呼叫方式錯誤 + 測試帳號 scope 設定問題。

二、V1.0 已完成的實作項目（不是規劃，是「真的存在」）

✅ 1) Plugin 啟動與基礎架構（穩定）

taipei-revival-attendance-system.php

includes/bootstrap.php

分層：includes/core/、includes/install/、includes/utils/、includes/api/、includes/frontend/、includes/admin/
這層架構可維持，不建議再「為了好看」重排。

✅ 2) Low-level DB Helper（utils/db.php）

includes/utils/db.php 已存在並作為唯一 DB 入口

後續 core service 不得直接用 $wpdb（只能走 tr_as_db_*）

✅ 3) 系統常數（core/constants.php）

DB version / Table names / Cap constants
⚠️ A5 問題（cap 字串 SSOT vs 程式）
✅ V1.2 唯一正確理解（寫死，不要再吵這個）

程式唯一真相 = constants.php

SSOT 裡的 cap 字面只當概念標籤

任何層（UI/auth/service）不得寫死 cap 字串，只能引用 TR_AS_CAP_* 常數

✅ 4) Auth / Scope 核心（core/auth.php）

includes/core/auth.php 已存在

定位：cap 註冊 / assert / user scope（leader/usher scope 讀 user_meta）

後續任何地方不要自行 user_can() 分流（統一走 auth 的 assert / scope）

✅ 5) Sessions Core Service（core/sessions.php）— V1.0 有做，但有瑕疵（V1.1 修正）

V1.0 曾有 global $wpdb 與 format 型別問題

✅ V1.1 已修正（見下方）

三、V1.1 已完成 / 已修正的實作項目（這是 V1.2 接手的基準）

✅ 1) People Core Service（core/people.php）已完成

list / count / get

leader scope list（依 auth.php leader scope）

admin create/update/set_active/move_scope

CSV import/export（summary only）

DB 一律走 utils/db.php

不碰 UI / attendance / stats

✅ 2) Attendance Core Service（core/attendance.php）已完成（方向正確，可用）

allowed statuses + normalize

matrix for leader（people in scope + attendance map）

matrix for admin/viewer（filters + attendance map）

mark（UPSERT，含 session writable + scope check）

bulk mark summary

✅ 3) install.php / sessions.php 的修正（V1.1 應做的修正點）
(a) install.php：UNIQUE KEY + NULL 欄位問題（高風險）

參與 UNIQUE 的欄位建議改成 NOT NULL DEFAULT ''，避免資料重複
(b) sessions.php：不得直接 $wpdb；flags 的 format 要用 %d

四、目前「還沒做、但已準備好」的部分（原本 V1.2 主要戰場）

（以下仍是戰場，但 V1.2 已做了「實測與根因定位」，見第八節）

includes/core/newcomers.php ⚠️（已存在，但目前有錯誤：db wrapper 呼叫方式 / 錯誤處理策略）

includes/core/headcount.php ⚠️（狀態未確認，需要 V1.3 以最新版 zip 檢查）

includes/api/ajax.php ⚠️（已存在 endpoints，但錯誤處理策略需統一；部分 request 可能回傳 0/非 JSON）

includes/frontend/leader.php ⚠️（前台能載入名單/場次，但寫入流程目前不穩定）

usher 前台流程 / admin UI（後台）⚠️（未進入）

五、V1.2「下一步唯一正確順序」（原順序保留）

Step 1：core/newcomers.php
Step 2：core/headcount.php
Step 3：api/ajax.php 接線
Step 4：frontend/leader.php 最小可用 UI
（不做統計、不做花俏、不做導出，先活著）

六、重要工程約束（硬規則）

SSOT v1.5 不重寫；但遇到文字不一致以 constants.php + 現有 code 為準

core service 不渲染 UI

core service 不直接用 $wpdb

權限判斷只走 auth.php 的 assert / scope

每支 core 檔案採「整檔覆蓋」交付（不要補丁式散改）

七、一句話交接總結（原句保留）

V1.0 完成地基（constants/auth/db/install/sessions 骨架）。
V1.1 完成 people + attendance 的核心實作，並指出 install/sessions 的「會害死人的點」要修正。
V1.2 不要重構、不准亂改世界觀，直接照順序做：newcomers → headcount → ajax 接線 → leader 最小 UI。

八、【V1.2 追加】2026-01-03 前台實測紀錄 + 已定位問題（非常重要）

這一節是 V1.2 真正新增的內容：我們現在不是「沒做」，而是「已經能跑到前台、能載入資料，但寫入卡死」，且根因已定位。
V1.3 請用你拿到的「最新版 zip」逐項對照。

8.1 V1.2 已確認「前台載入面」哪些是正常的（代表已經接上了）

✅ Leader 測試頁可以開（頁面標題：點名測試 – Leader）
✅ 場次下拉選單可出現（例如：20260105 / 主日崇拜 / 10:00 / 10:00）
✅ 名單表格可載入（例如王小明、李小華、陳小美；小隊=4，小組=D1小組）
✅ Network 看到至少這些呼叫出現過：

admin-ajax.php?action=tr_as_get_sessions

admin-ajax.php?action=tr_as_get_attendance_matrix

admin-ajax.php?action=tr_as_get_newcomers

意味著：sessions / people / matrix 的讀取路徑基本是通的。

8.2 V1.2 實測遇到的錯誤「有三種」，且彼此有因果關係
錯誤類型 A（致命）

前台顯示 / AJAX 回應：

Cannot throw objects that do not implement Throwable
或前台只顯示：ajax failed，Network response 可能是 0 或非 JSON

根因判定（寫死）：

core service 內使用了 throw new WP_Error(...)（WP_Error 不是 Throwable）

這會造成 PHP fatal error → admin-ajax 回傳不是 JSON → 前端就只能顯示 ajax failed

✅ V1.3 接手任務（必做）：

用最新版 zip 全域搜尋：throw new WP_Error

必須統一錯誤策略：

要嘛 core 全部改「return WP_Error」

要嘛 core 全部改「throw Exception/Throwable」

不能混用，混用就會出現這種 fatal → ajax failed

錯誤類型 B（scope 不足，不是 bug）

AJAX 回應：

tr_as_no_leader_scope / leader scope missing

根因判定（寫死）：

目前 auth.php 設計：admin 角色有 TR_AS_CAP_ADMIN，但 tr_as_auth_get_user_scope() 對 admin 回傳 leader=null

newcomers/attendance（leader path）需要 leader scope（group_name/team_no 等 user_meta）

所以 admin 直接測 leader endpoints 會缺 scope 是設計結果，不是 DB 壞。

✅ V1.3 接手任務（必做）：

決定「測試策略」：

建立 leader-test 帳號 + 設定 user_meta scope（最乾淨）

或在測試期間允許 admin 也能帶 leader scope（僅測試用；會污染邊界，需明確註記）

V1.2 判定：要讓 admin 當 leader 測，是額外需求；不是 core bug。

錯誤類型 C（newcomers DB wrapper 呼叫方式錯）

AJAX 回應：

tr_as_db_prepare(): Argument #2 ($args) must be of type array, int given

發生位置：/includes/core/newcomers.php on line 79
（你提供的 line 79 呼叫方式是「多參數」，但 wrapper 需要 array）

根因判定（寫死）：

tr_as_db_prepare() 的 signature 很可能是：tr_as_db_prepare($sql, array $args)

但 newcomers.php 以類似 $wpdb->prepare($sql, $a, $b, $c...) 的方式傳入
→ 導致第二參數變 int，而不是 array

✅ V1.3 接手任務（必做）：

打開最新版 zip 的 includes/utils/db.php，確認 tr_as_db_prepare 的函式簽名

依簽名修正 newcomers.php 所有 tr_as_db_prepare(...) 呼叫方式（通常要改成「args array」）

8.3 DB 觀察（V1.2 實測時的現象）

✅ 已看到 tables 存在：

tr_as_sessions

tr_as_people

tr_as_attendance

tr_as_newcomers

tr_as_headcount

⚠️ 實測時（寫入失敗時）觀察到：

SELECT * FROM tr_as_attendance → 無資料行

SELECT * FROM tr_as_newcomers → 無資料行

判定：

不是 table 沒建，是 寫入路徑（AJAX → core → DB）在錯誤處理 / wrapper 使用上中斷。

8.4 「點名按鈕按了沒有任何 AJAX」的補充判定（待 V1.3 以 zip 驗證）

V1.2 看到過兩種狀況：

有時 Network 只有 get_sessions / get_attendance_matrix / get_newcomers，但 看不到 tr_as_mark_attendance

前台按鈕點完重整又回到未點，DB 也沒資料

可能原因（需 V1.3 以最新版 zip 對照）：

JS click handler 沒綁上（button 的 class/data-attr 與 JS selector 不一致）

AJAX 其實送了但回傳非 JSON / 0，前端中斷顯示成「沒反應」

cache/版本問題（同頁顯示 build: 1767444883，但實際載入的 js 可能不是你以為的版本）

✅ V1.3 接手任務（必做）：

以最新版 zip 檢查 leader 前端 JS：

事件綁定 selector 是否匹配目前 UI DOM

mark attendance 的 AJAX action 名稱是否一致（tr_as_mark_attendance）

nonce/session_id/person_id/status 是否有送出

並在 Network 中確認是否有任何 admin-ajax.php POST 發生（不要只看畫面）

九、【V1.2 追加】V1.3 接手「必做檢查清單」（照順序，不要跳）

你要的是「V1.3 看到 SSOT 不會不知道做到哪」。
這裡直接給 V1.3 一份開工檢查順序（只檢查，不先重構）。

9.1 先確認「你手上的 zip 是最新版」

以 zip 內檔案內容為準，不要用對話貼的片段當準據

尤其：includes/utils/db.php, includes/core/newcomers.php, includes/api/ajax.php, includes/frontend/*

9.2 統一錯誤策略（先做這個，否則你永遠在 ajax failed）

全域搜尋：throw new WP_Error

決定並統一：

core 全 return WP_Error，ajax 只判斷 instanceof

或 core 全 throw Throwable，ajax 用 try/catch

不允許混用

9.3 修 newcomers.php 的 tr_as_db_prepare 呼叫方式

以 utils/db.php 的 signature 為準

把所有 prepare 呼叫改成正確格式（多數情況是第二參數要 array）

9.4 建立可測帳號（不要用 admin 硬測 leader）

建 leader-test 使用者

賦予 TR_AS_CAP_LEADER

設定 user_meta：

tr_as_branch_id / tr_as_region / tr_as_sub_region / tr_as_group_name / tr_as_team_no

用 leader-test 登入測 newcomers / mark

9.5 再測寫入結果（用 DB 驗證，不要只看畫面）

新朋友儲存 → tr_as_newcomers 應新增/更新 1 row

點名寫入 → tr_as_attendance 應新增/更新 rows

若 DB 無資料：回去查 Network response 是否非 JSON 或 0

十、【V1.2 自我聲明】V1.2 這一輪我做了什麼、沒做什麼（避免誤會）

✅ 我（V1.2）本輪實際完成的是：

指導你用 Network/Response 定位 error code

彙整三種錯誤類型並給出「唯一根因判定」

明確指出：目前不是要加功能，是要先把系統變成可測

把「為什麼 admin 不能當 leader 測」釐清成 scope 設計問題，而非 DB 問題

指出 newcomers.php 的 tr_as_db_prepare 呼叫方式與 wrapper signature 不一致，是必修 bug

❌ 我（V1.2）本輪沒有交付任何「可覆蓋代碼」
（因你最後指令是：現在不要我做，要我改交接文件給 V1.3）
