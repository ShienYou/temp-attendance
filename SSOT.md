📘 聚會點名系統 WordPress Plugin — SSOT 交接文件（重製完整版）
Version：v1.5 / 2026-01-03（以此份為唯一 SSOT）
（v1.5 = 整合本次對話：插件骨架/檔案架構定案、JS/CSS/AJAX 規劃、避免未來前台後台踩雷的「邊界寫死」、以及 v1.x 實作路線防誤讀註解）

專案摘要

目標：做一個可長期運作的聚會點名＋統計系統
支援聚會類型：主日崇拜 / 禱告會 / 小組聚會 / 特別聚會
支援比較：週 / 月 / 季 / 年，且必須可跨年份（週採 ISO week）

同一場聚會需要同時存在兩套來源（允許不一致）：

小組長名單型點名（逐人、有名單、有追責）＋ 小組長新朋友人數（只填數字）

招待總人數統計（Headcount）（只有數字、可分堂點/場次/成人小孩/線上渠道…）

部署環境：Shien 現有 Cloudways WordPress（小伺服器）
硬性原則：此點名系統必須是獨立 plugin；與讀經打卡 plugin 互不影響（任一停用不影響另一個）

本版最重要硬規則（寫死）：
後台（wp-admin）在開發時必須同步符合「未來可做前台後台」的架構需求。
未來要做前台同工版後台時，不允許被說成要重做一個系統。
因此本系統必須採「一套核心引擎 + 多個介面入口」設計：
wp-admin 後台與前台同工版後台，只是不同 UI，必須共用同一套核心邏輯、同一套權限檢查、同一套資料存取與統計計算。

文件定位（不可改）

任何新加入的「代碼小玲 / 工程師」只看這份就必須能開工
不需回溯對話、不需看舊文件、不准自行腦補需求
本 SSOT 就是唯一準據，不允許叫下一位去參考其他 SSOT

本 SSOT 會引用舊插件 bible-checkin.zip 內「可搬用設計」作為參考
（但不共用任何表、任何 option key、任何程式碼）

專案目標與不做事項（不可改）
1.1 核心目標

小組長在聚會當下能快速完成點名（手機優先）

系統自動做統計：分堂 / 牧區 / 小區 / 小組（必要）

點名狀態必含：出席 + 現場/線上

支援：匯入既有名單、點名

新朋友處理（已定案）：前台不新增人名，只填新朋友人數

支援多聚會類型：主日 / 禱告會 / 小組 / 特別聚會

支援比較：週對週、月對月、季對季、年對年，且需跨年份

招待總人數統計（Headcount）

每一場聚會，招待可填報「總人數」與分項（成人/小孩、實體/線上、ZOOM/YT、分堂/熱點…）

與小組長名單型點名允許不一致，系統需能同時呈現與比較

1.2 明確不做（本案排除）

年齡 / 性別 / 已婚未婚…等人口統計（另案）

AI 分析 / 推薦 / 關懷提醒（先不做）

不追求個資完美，只追求「可用、可統計、可長期運作」

角色與權限（已定案：每人一帳號登入）
2.1 核心決策（定案）

每位小組長（或點名者）都要有自己的帳號登入
目的：才能做到「登入的人只能看到自己的小組」，並可追責（誰點的、何時點的）

Cloudways 小伺服器承受度：
帳號數量不是瓶頸（200–500 個小組長也 OK）；真正瓶頸是「一次載入多少人、寫入頻率」。v1.x 以「整包送出」降低 DB 壓力。

2.2 權限角色（建議固定）

attendance_admin（同工/你）：全域管理（人員匯入、場次建立、統計、匯出）

attendance_leader（小組長）：只能看/點名自己小組（只能寫自己小組的資料）

attendance_usher（招待）：只能填報自己被指派的場次/堂點/據點的 Headcount（數字統計），不可改人員名單與小組長點名

attendance_viewer（牧者/只看）：只能看統計，不可寫入

參考舊 plugin 的 capability 註冊模式（僅參考概念）：
bible-checkin.php 的 bc_register_capabilities()、bc_sync_church_accounts_caps()

2.3 帳號綁定範圍（必做）

A) 小組長帳號綁定（必做）：存在 user_meta 或 mapping table
branch_id / region / sub_region / group_name（至少 group_name；為統計一致建議全綁）
登入後：直接進自己小組點名介面

B) 招待帳號綁定（必做）：存在 user_meta 或 mapping table
可見/可填報的範圍（至少其一）：
venue_scope（例如：台北母堂/新莊分堂/羅東分堂/某熱點/ZOOM/YT…）
meeting_type_scope（例如：主日崇拜/跨年禱告會…）
service_slot_scope（例如：週六第一堂/週日第二堂/線上週六第一堂…）
招待登入後：只看到自己可填的場次清單與輸入表單

核心資料設計（SSOT）
3.1 Person（正式名單）最低欄位（必有）

branch_id（分堂）
region（牧區）
sub_region（小區）
group_name（小組）
name（姓名）
is_active（啟用/停用）

重要：新朋友不在前台建立 Person。
正式名單的新增/移組/停用，只能由後台 People 管理完成（未來前台同工版後台也只能走同一套 People 管理邏輯）。

3.2 小組長點名資料：分成兩塊（現行規格）

A) 名單型點名（有名字/有 person_id）
小組長只勾選「正式名單」的人
每人可選：未點名 / 出席-現場 / 出席-線上

B) 新朋友（無名字、只有人數）
同一場次同一小組，另有欄位：newcomers_count（整數）
小組長回報總數 =（名單出席數）+（newcomers_count）

新朋友若後來成為正式小組成員：由同工在 People 新增/轉正（新增人名），前台不處理。

3.3 招待 Headcount（現行規格）

招待的資料不是「逐人」，而是「分項人數」。
同一場次可以有多筆分項（例如：台北母堂成人、台北母堂小孩、ZOOM、YT、某熱點…）。

Headcount 設計原則（寫死）
Headcount 與小組長點名允許不一致（不強制對齊）
Headcount 不生成 person，不回寫小組名單
Headcount 是「場次/堂點/渠道」層級的 aggregate 數字

聚會場次（Meeting Sessions）
4.1 核心規則（定案）

每個月（或下一季/下一年）同工先建立「可點名的場次清單」
小組長只能從已建立的場次選擇點名（不可自行輸入日期）
招待也只能填報已建立的場次（不可自行輸入日期）

4.2 Meeting Type（現行規格：四大類）

主日崇拜
禱告會
小組聚會
特別聚會（任何不定期或額外聚會）

寫死規則：meeting_type 是資料值域可擴充，不用寫死在 if/else。

4.3 Service Slot（為了對齊牧者表格）

sessions 必須能表達「同一天同 meeting_type 的多場次」。
因此 sessions 必須新增欄位：
service_slot（字串，可空，但主日/大型聚會通常不空）
display_text（可空）：例如「10:00」「11:30」等補充

4.4 Sessions 欄位（寫死）

meeting_type
ymd（YYYYMMDD）
service_slot（可空，但主日/大型聚會通常不空）
display_text（可空）
is_open
is_editable
created_at

點名資料（Records）規格
5.1 名單型點名（逐人紀錄）每筆必包含

session_id
person_id
attend_status（未點名 / 出席）
attend_mode（現場 / 線上）——只在出席時有值
marked_by_user_id（追責）
marked_at

5.2 小組長新朋友人數（無名單）每筆必包含

同一場次 + 同一小組一筆即可：
session_id
scope（對應 group_name + branch/region/sub_region，或 leader_id 反查）
newcomers_count（整數，允許 0）
marked_by_user_id
marked_at

5.3 招待 Headcount 每筆必包含

同一場次可多筆（每筆代表一個分項）：
session_id
venue（字串）
audience（字串，可擴充）
mode/channel（字串，可擴充）
headcount（整數）
reported_by_user_id
reported_at
note（可空）

統計需求（現行規格）
6.1 必須支援的維度（不可省）

分堂 / 牧區 / 小區 / 小組（名單型點名）
同一場次（小組長來源）：現場數、線上數、新朋友數、總數
同一場次（招待來源）：可按 venue/audience/mode 彙總出實體小計、線上小計、總計

6.2 同場次雙來源並存（寫死）

Stats 必須能在同一場次呈現兩套數字（允許不一致）：
Leader（小組長）：名單出席 + newcomers_count
Usher（招待）：headcount 分項彙總（實體/線上/總計）

並提供差異（至少後台顯示）：
diff = usher_total - leader_total（僅顯示，不強制對齊）

6.3 比較需求（寫死）

對 meeting_type + service_slot 要能做：
週對週 / 月對月 / 季對季 / 年對年
且可跨年份

6.4 跨年份規則（寫死，避免踩雷）

週：必須用 ISO week（例如 2026-W01），不可用「月份推週」
月：YYYY-MM
季：YYYY-Q
年：YYYY

前台流程（小組長）
7.1 進入點名（定案）

小組長登入後 → 直接進自己小組點名介面
不需要選分堂/牧區/小區/小組
可保留直達連結：未登入先登入再導回

7.2 點名 UI（現行規格）

顯示自己小組正式名單
每人可選：未點名（預設）/ 出席-現場 / 出席-線上
另有欄位：新朋友人數 newcomers_count（只填數字，不填名字）
可選：顯示名單出席數、新朋友數、總數（前端算即可）

7.3 送出方式（定案）

v1.x 採整包送出（POST）為主
一次送出：逐人勾選結果 + newcomers_count

前台/後台流程（招待 Headcount）
8.1 招待填報入口（定案）

招待登入後可以在：
後台（WP Admin 的 Headcount Tab）填報
或前台（專用頁面 shortcode）填報

重要：不管入口在哪裡，必須共用同一套 headcount 寫入與權限檢查邏輯（避免兩套規則）。

8.2 招待填報 UI（現行規格）

選擇場次（ymd + meeting_type + service_slot）
輸入多筆分項（可新增列）：venue / audience / mode-channel / headcount
送出（整包送出，避免半成功）

後台功能（同工/牧者）
9.1 Tabs（建議）

Sessions：建立/批次建立（下月/下季/全年）
People：CSV 匯入/匯出、停用、移組、轉正新增（新朋友轉正）
Leader Attendance：查詢/匯出小組長名單型點名（可選 tab）
Headcount：招待填報/查詢/匯出
Stats：統計總覽（雙來源並存 + 比較 + 跨年）
Danger：清空資料、重算（僅 admin）

9.2 People 匯入/匯出（沿用舊系統習慣）

CSV 欄位順序：
name, branch, region, sub_region, group_name, team_no（可空）, is_active

保留兩個規則：
匯出 CSV 加 UTF-8 BOM（Excel 中文不亂碼）
匯入只回摘要（inserted/duplicates/skipped），不噴明細

快取與穩定性（必做）
10.1 前台頁面 no-cache（必做）

DONOTCACHEPAGE / DONOTCACHEOBJECT / DONOTCACHEDB
nocache_headers()
Cache-Control: no-store, no-cache…

10.2 寫入 no-cache（若有 AJAX 版本時必做）

先手動送最嚴格 header，再 nocache_headers()

10.3 手機 bfcache（若有即時狀態顯示時）

pageshow（persisted）時刷新/重算顯示，避免返回頁狀態錯亂

舊插件 bible-checkin.zip 可參考清單（只參考套路）

主入口：caps、載入、no-cache 的模式
install.php：建表套路
db.php：DB helper 概念
csv.php：BOM + 摘要回報概念
admin tabs router 的概念
前台流程與 no-cache 的概念

禁止：共用任何 bc_* 資料表、option key、程式碼

不可踩雷（直接寫死）

必須獨立 plugin（自己的 table prefix、自己的 option keys）
不讀寫任何 bc_* 表
不接受匿名寫入
寫入必檢查 capability + nonce
小組長寫入必檢查「只能寫自己小組」
招待寫入必檢查「只能寫自己被指派的 venue/service_slot 範圍」
場次必須先由同工建立；前台不可自訂日期
新朋友前台不建人名、不建 person；只填 newcomers_count
meeting_type 必須可擴充（資料值域），不可寫死
主日/大型聚會多堂：必須用 service_slot 表達，不可硬塞成 display_text
統計雙來源允許不一致：不可強制對齊、不可自動互相覆蓋
週/月/季/年比較需跨年；週必用 ISO week

系統架構硬規則：必須支援「未來前台後台」，不准重做（寫死）
13.1 一句話定義（寫死）

本系統必須是一套核心引擎（核心規則、寫入、統計、權限）
外面可以有多個入口介面：
入口 1：wp-admin 後台
入口 2：小組長前台點名頁
入口 3：招待前台/後台填報頁
入口 4：未來同工前台後台（前台管理介面、儀表板、曲線圖）

介面可以多個，但核心只能有一套。
未來做前台後台時，不允許複製一套新邏輯，不允許改成兩個系統。

13.2 核心層必須獨立（寫死）

權限檢查（capability + scope）
寫入驗證（nonce、session 是否可寫、是否在範圍內）
資料一致性（名單型 vs 新朋友數字 vs headcount 分項）
統計計算（彙總、週/月/季/年比較、跨年規則）
匯入匯出（BOM、摘要回報、重複判定）
no-cache（前台寫入與前台頁面規則）

13.3 UI 層只能做「輸入與呈現」（寫死）

wp-admin 或前台同工版後台都只能：
顯示資料、收集表單、呼叫核心層函式或統一的 API
不允許在 UI 檔案內直接寫一堆 SQL 規則或統計演算法
不允許把權限與範圍檢查散落在各頁面各寫一次

13.4 統一入口方式（寫死，避免未來重做）

不管是 wp-admin 或前台後台，都必須走同一套「寫入入口」與「查詢入口」。可用兩種方式擇一，但必須一致：

A) 直接呼叫核心服務層（PHP service functions/classes）
B) 統一走 WP AJAX / REST API（後台與前台共用同一個 endpoint）

但不能：
後台走一套 SQL 寫入；前台又走另一套 SQL 寫入。
那就是等於兩個系統，必死。

v1.x 實作路線防誤讀註解（寫死，非新需求、非改規格）：
v1.x 建議採「核心 PHP service layer + 統一 wrapper function」先把核心鑄好；
REST / AJAX 僅作為未來前台後台的可選薄封裝（thin wrapper），不得在 v1.x 先被 endpoint 設計綁死。

13.5 未來前台後台要做的事，不得要求重建資料（寫死）

未來要做前台同工版後台（更大螢幕、更好看曲線圖）時，必須只需要：
新增前台頁面與 UI（shortcode/template）
串接既有核心統計資料
不准說要改 DB 結構、不准說要重建統計口徑、不准說要重做權限

13.6 禁止的寫法（寫死，這些會導致未來重做）

把統計 SQL 寫死在 wp-admin 頁面檔案中
把權限檢查只做在某一個表單頁面中
把 headcount 的彙總規則只寫在某一個圖表頁中
把週/月/季/年的跨年規則分散到各頁面各算一份
任何複製貼上導致「兩套邏輯」的行為

附錄 A｜Core Service Layer API 白名單（寫死）

本附錄唯一目的：鎖死「所有 admin / frontend / ajax 只能怎麼呼叫 core」，防止任何人把業務邏輯寫進 UI。
規則（寫死）：

wp-admin / frontend / api/ajax.php 只能呼叫本附錄列出的 core service layer function。

UI / AJAX 只能做：收參數、nonce/capability 初檢（可選）、呼叫 core、render/回 JSON。

❌ UI 不得自行做：scope 判斷、session 可寫判斷、寫入驗證、統計彙總、跨年/ISO week 計算、CSV 去重/摘要統計。

本附錄 ❌ 不含任何 SQL、❌ 不含任何 PHP 實作、❌ 不新增任何需求或新功能、❌ 不調整任何資料表結構。

若某行為尚未定義，僅列出 function signature（含參數/回傳/權限/標示），不補邏輯、不補實作。

A.0 名詞與角色（寫死）
admin = attendance_admin
leader = attendance_leader
usher = attendance_usher
viewer = attendance_viewer

A.1 No-Cache（反快取守門）

tr_as_nocache_apply_page_guards( string $context ): void
參數：

context（string）：頁面情境，例如 leader_page / usher_page / admin_write / ajax_write
回傳結構：

無（只負責套用 no-cache header 與 DONOTCACHE* 旗標）
呼叫權限：admin / leader / usher / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行拼 header、或各頁各寫一套 no-cache 規則

tr_as_nocache_apply_write_guards( string $context ): void
參數：

context（string）：寫入情境，例如 leader_submit / usher_submit / admin_import
回傳結構：

無
呼叫權限：admin / leader / usher / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 在寫入流程自行處理 cache 例外與 bfcache 修正

A.2 Auth / Scope（capability + scope 唯一口徑）

tr_as_auth_assert_capability( int $user_id, string $capability ): void
參數：

user_id（int）：目前登入者 ID

capability（string）：attendance_admin / attendance_leader / attendance_usher / attendance_viewer 之一
回傳結構：

無（未通過時中止或拋錯）
呼叫權限：admin / leader / usher / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行判斷 role/capability 或分散檢查邏輯

tr_as_auth_get_user_scope( int $user_id ): array
參數：

user_id（int）
回傳結構（資料形狀）：

leader scope（若有）：{ branch_id, region, sub_region, group_name, team_no? }

usher scope（若有）：{ venue_scope[], meeting_type_scope[], service_slot_scope[] }

可能回傳：{ leader: {...}|null, usher: {...}|null }
呼叫權限：admin / leader / usher / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 直接讀 user_meta / mapping table 自己拼 scope

tr_as_auth_assert_leader_scope_for_session( int $user_id, int $session_id ): void
參數：

user_id（int）：leader 使用者 ID

session_id（int）：場次 ID
回傳結構：

無
呼叫權限：leader
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行判斷「登入者是否能寫此場次的本小組資料」

tr_as_auth_assert_usher_scope_for_session( int $user_id, int $session_id ): void
參數：

user_id（int）：usher 使用者 ID

session_id（int）：場次 ID
回傳結構：

無
呼叫權限：usher
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行判斷 venue/service_slot/meeting_type 的可填範圍

tr_as_auth_assert_admin_only( int $user_id ): void
參數：

user_id（int）
回傳結構：

無
呼叫權限：admin
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 用 if/else 或硬寫 user_login 來擋權限

A.3 Sessions（場次清單 + 可寫狀態）

tr_as_sessions_list( array $filters ): array
參數（filters 為 array，可空）：

meeting_type?（string）

ymd_from?（string，YYYYMMDD）

ymd_to?（string，YYYYMMDD）

is_open?（bool）
回傳結構（資料形狀）：

[{ session_id, meeting_type, ymd, service_slot, display_text, is_open, is_editable, created_at }]
呼叫權限：admin / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自己做場次查詢與排序口徑

tr_as_sessions_get( int $session_id ): array
參數：

session_id（int）
回傳結構：

{ session_id, meeting_type, ymd, service_slot, display_text, is_open, is_editable, created_at }
呼叫權限：admin / leader / usher / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 直接查 sessions 或推測場次狀態

tr_as_sessions_get_available_for_user( int $user_id ): array
參數：

user_id（int）
回傳結構：

[{ session_id, meeting_type, ymd, service_slot, display_text, is_open, is_editable }]
呼叫權限：admin / leader / usher
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 以「前端日期輸入」或自行篩選可點名場次

tr_as_sessions_assert_writable( int $session_id ): void
參數：

session_id（int）
回傳結構：

無
呼叫權限：admin / leader / usher
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自己判斷 is_open/is_editable 或跳過可寫驗證

tr_as_sessions_create_bulk( int $user_id, array $items ): array
參數：

user_id（int）：admin 使用者 ID

items（array）：[{ meeting_type, ymd, service_slot?, display_text?, is_open?, is_editable? }]
回傳結構：

{ inserted, duplicated, skipped }（摘要）
呼叫權限：admin
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自己寫入 sessions 或各頁各寫一套「批次建立」規則

tr_as_sessions_update_flags( int $user_id, int $session_id, array $patch ): void
參數：

user_id（int）：admin 使用者 ID

session_id（int）

patch（array）：{ is_open?, is_editable?, display_text? }
回傳結構：

無
呼叫權限：admin
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 直接更新 sessions 或把 business rule 散落在頁面

A.4 People（正式名單：查詢 / 匯入 / 匯出 / 啟用停用 / 移組）

tr_as_people_list( array $filters ): array
參數（filters 為 array，可空）：

branch_id?（int）

region?（string）

sub_region?（string）

group_name?（string）

is_active?（bool）

search?（string，姓名模糊搜尋）
回傳結構：

[{ person_id, branch_id, region, sub_region, group_name, name, is_active }]
呼叫權限：admin / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 直接查 people 或自行拼過濾條件

tr_as_people_get_for_leader( int $user_id ): array
參數：

user_id（int）：leader 使用者 ID
回傳結構：

[{ person_id, name, branch_id, region, sub_region, group_name, is_active }]
呼叫權限：leader
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自己用 scope 去查人，或在前台做名單拼裝

tr_as_people_import_csv( int $user_id, string $csv_raw ): array
參數：

user_id（int）：admin 使用者 ID

csv_raw（string）：CSV 原文內容
回傳結構（摘要，寫死）：

{ inserted, duplicated, skipped }
呼叫權限：admin
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 一行一行處理匯入與去重，也禁止噴明細錯誤列表

tr_as_people_export_csv( int $user_id, array $filters ): string
參數：

user_id（int）：admin 使用者 ID

filters（array）：同 tr_as_people_list filters
回傳結構：

CSV 內容字串（含 UTF-8 BOM）
呼叫權限：admin
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自己組 CSV、自己加 BOM、自己決定欄位順序

tr_as_people_set_active( int $user_id, int $person_id, bool $is_active ): void
參數：

user_id（int）：admin 使用者 ID

person_id（int）

is_active（bool）
回傳結構：

無
呼叫權限：admin
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 直接更新 people、或在頁面內塞規則

tr_as_people_move_group( int $user_id, int $person_id, array $to_scope ): void
參數：

user_id（int）：admin 使用者 ID

person_id（int）

to_scope（array）：{ branch_id, region, sub_region, group_name, team_no? }
回傳結構：

無
呼叫權限：admin
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自己做移組規則或直接改欄位

A.5 Attendance（名單型點名：讀取 / 整包送出）

tr_as_attendance_get_group_snapshot( int $user_id, int $session_id ): array
參數：

user_id（int）：leader 使用者 ID

session_id（int）
回傳結構（資料形狀）：

{
people: [{ person_id, name, is_active }],
records: [{ person_id, attend_status, attend_mode, marked_by_user_id, marked_at }],
meta: { session_id, meeting_type, ymd, service_slot, is_open, is_editable }
}
呼叫權限：leader
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自己拼「名單 + 既有點名狀態 + 場次狀態」或自行推測預設值

tr_as_attendance_submit_bulk( int $user_id, int $session_id, array $records ): array
參數：

user_id（int）：leader 使用者 ID

session_id（int）

records（array）：[{ person_id, attend_status, attend_mode? }]
回傳結構（摘要）：

{ saved, skipped, updated }（摘要即可；不得回噴每人明細）
呼叫權限：leader
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 直接寫 attendance、也禁止 UI 自行處理可寫/範圍/追責欄位

A.6 Newcomers（新朋友人數：讀取 / 送出）

tr_as_newcomers_get( int $user_id, int $session_id ): array
參數：

user_id（int）：leader 使用者 ID

session_id（int）
回傳結構：

{ session_id, newcomers_count, marked_by_user_id, marked_at }（若無資料則回 newcomers_count=0 或 null，依 core 定義）
呼叫權限：leader
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行查 newcomers 或自行決定是否存在一筆資料

tr_as_newcomers_submit( int $user_id, int $session_id, int $newcomers_count ): void
參數：

user_id（int）：leader 使用者 ID

session_id（int）

newcomers_count（int）：整數，允許 0
回傳結構：

無
呼叫權限：leader
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 建 person、也禁止 UI 自己把 newcomers 加進統計口徑

A.7 Headcount（招待分項：讀取 / 整包送出）

tr_as_headcount_get_entries( int $user_id, int $session_id ): array
參數：

user_id（int）：usher 使用者 ID

session_id（int）
回傳結構（資料形狀）：

{
session: { session_id, meeting_type, ymd, service_slot, display_text, is_open, is_editable },
entries: [{ entry_id, venue, audience, mode, headcount, note, reported_by_user_id, reported_at }]
}
呼叫權限：usher
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行做「可填範圍」判斷或自行查 headcount 表

tr_as_headcount_submit_bulk( int $user_id, int $session_id, array $entries ): array
參數：

user_id（int）：usher 使用者 ID

session_id（int）

entries（array）：[{ venue, audience, mode, headcount, note? }]
回傳結構（摘要）：

{ saved, deleted, updated }（摘要即可）
呼叫權限：usher
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行做「半成功」寫入流程或逐筆寫入規則

A.8 Stats（雙來源並存 + 比較 + 跨年規則，唯一口徑）

tr_as_stats_get_session_totals( int $user_id, int $session_id ): array
參數：

user_id（int）：admin 或 viewer 使用者 ID

session_id（int）
回傳結構（資料形狀，寫死）：

{
session: { session_id, meeting_type, ymd, service_slot, display_text },
leader: { onsite, online, newcomers, total },
usher: { total, breakdown?: [{ venue, audience, mode, headcount }] },
diff: { total }
}
呼叫權限：admin / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行彙總 leader/usher、也禁止 UI 自行計 diff

tr_as_stats_get_rollup( int $user_id, array $filters ): array
參數：

user_id（int）：admin 或 viewer

filters（array，可空）：{ meeting_type?, service_slot?, ymd_from?, ymd_to?, branch_id?, region?, sub_region?, group_name? }
回傳結構（資料形狀）：

{
leader: { rows: [{ scope..., onsite, online, newcomers, total }] },
usher: { rows: [{ venue?, audience?, mode?, total }] },
diff?: { total }
}
呼叫權限：admin / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 在頁面內寫任何 rollup/分組彙總規則

tr_as_stats_get_period_comparison( int $user_id, array $criteria ): array
參數：

user_id（int）：admin 或 viewer

criteria（array，寫死）：{ period_type, period_key, meeting_type?, service_slot? }

period_type：week / month / quarter / year

period_key：由 core/time 唯一口徑產生（例如 2026-W01、2026-01、2026-Q1、2026）
回傳結構（資料形狀）：

{
criteria: {...},
current: { leader_total, usher_total },
previous: { leader_total, usher_total },
diff: { leader_total, usher_total }
}
呼叫權限：admin / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行算 week/month/quarter/year 對照與跨年規則

A.9 Time（週/月/季/年 key 唯一口徑，含跨年 / ISO week）

tr_as_time_period_key_from_ymd( string $ymd, string $period_type ): string
參數：

ymd（string）：YYYYMMDD

period_type（string）：week / month / quarter / year
回傳結構：

string：week => YYYY-Wxx（ISO week），month => YYYY-MM，quarter => YYYY-Qx，year => YYYY
呼叫權限：admin / leader / usher / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行做 ISO week / 跨年換算

tr_as_time_period_range( string $period_type, string $period_key ): array
參數：

period_type（string）：week / month / quarter / year

period_key（string）：例如 2026-W01 / 2026-01 / 2026-Q1 / 2026
回傳結構：

{ ymd_from, ymd_to }（YYYYMMDD，含起訖規則由 core 唯一口徑決定）
呼叫權限：admin / leader / usher / viewer
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 UI 自行推算週期起訖、避免跨年週錯歸屬

A.10 API/AJAX（薄封裝只能呼叫 core，不得實作業務）

tr_as_api_handle_leader_submit( array $payload ): array
參數：

payload（array）：包含 session_id、records、newcomers_count、nonce 等（具體欄位由既有 UI 表單決定）
回傳結構：

{ ok, message?, summary? }（JSON 形狀）
呼叫權限：leader
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 ajax.php 內自行做 scope、可寫、寫入、統計任何規則（只能呼叫 core）

tr_as_api_handle_usher_submit( array $payload ): array
參數：

payload（array）：包含 session_id、entries、nonce 等
回傳結構：

{ ok, message?, summary? }
呼叫權限：usher
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 ajax.php 內自行做 scope、可寫、寫入任何規則（只能呼叫 core）

tr_as_api_handle_admin_import_people( array $payload ): array
參數：

payload（array）：包含 csv_raw、nonce 等
回傳結構：

{ ok, inserted, duplicated, skipped }
呼叫權限：admin
標示：
✅ 允許 UI / AJAX 呼叫
❌ 禁止 ajax.php 內自己做 CSV 去重/處理（只能呼叫 core）

v1.5 新增：插件骨架與檔案架構（已定案，寫死）
16.1 插件命名與前綴（寫死）

插件名稱必帶 Taipei Revival 前綴（避免與其他插件混淆）。
插件骨架採「可啟用的最小安全版本」起跑，逐檔填實作。

16.2 檔案架構（寫死，AI/人類工程師友善）

原則：核心在 core，入口在 admin/frontend/api，工具在 utils，資產在 assets。
UI 不寫 SQL；SQL/統計/權限只在 core。

taipei-revival-attendance-system/
│
├─ taipei-revival-attendance-system.php
├─ uninstall.php
│
├─ includes/
│ ├─ bootstrap.php
│ │
│ ├─ core/
│ │ ├─ constants.php # 表名/版本 key 唯一來源
│ │ ├─ auth.php # capability + scope（唯一口徑）
│ │ ├─ sessions.php
│ │ ├─ people.php
│ │ ├─ attendance.php
│ │ ├─ newcomers.php
│ │ ├─ headcount.php
│ │ ├─ stats.php
│ │ ├─ time.php # 週/月/季/年 key 唯一口徑（業務規則）
│ │ ├─ csv.php
│ │ └─ nocache.php # 反 cache 防護（no-cache + bfcache）
│ │
│ ├─ install/
│ │ └─ install.php # schema only（使用 core/constants.php）
│ │
│ ├─ admin/
│ │ ├─ admin.php
│ │ ├─ pages/
│ │ │ ├─ sessions.php
│ │ │ ├─ people.php
│ │ │ ├─ attendance.php
│ │ │ ├─ headcount.php
│ │ │ └─ stats.php
│ │ └─ assets/
│ │ ├─ admin.js
│ │ └─ admin.css
│ │
│ ├─ frontend/
│ │ ├─ leader.php
│ │ ├─ usher.php
│ │ └─ assets/
│ │ ├─ leader.js
│ │ ├─ leader.css
│ │ ├─ usher.js
│ │ └─ usher.css
│ │
│ ├─ api/
│ │ └─ ajax.php # 統一 AJAX 入口（薄封裝，呼叫 core）
│ │
│ ├─ assets/
│ │ └─ shared/
│ │ └─ nocache-guard.js # pageshow persisted 等共用矯正
│ │
│ └─ utils/
│ ├─ db.php # 只做低階能力（prepare/transaction），禁止業務查詢
│ └─ time.php # 只做純 parse/format helper，不得產生週期 key（可選保留）
│
└─ readme.txt

16.3 JS/CSS 引入版本號（寫死）

所有 enqueue 的 JS/CSS 版本號必須使用「檔案時間」作為版本（避免快取雷）。
做法：使用 filemtime() 作為 version。

16.4 AJAX / REST 規範（寫死）

v1.x 可先不實作完整 API，但必須先保留 includes/api/ajax.php 作為統一入口。
AJAX handler 只能：收參數 → nonce/capability 初檢 → 呼叫 core → 回 JSON。
不得在 ajax.php 內實作業務統計/SQL/權限細節（那會導致兩套邏輯）。

16.5 防踩雷：時間口徑與 DB helper 邊界（寫死）

週/月/季/年 key（含跨年/ISO week）屬於業務規則，只能在 core/time.php（或 core/stats+core/time 的唯一口徑）定義
utils/time.php 不得產生週期 key

utils/db.php 只能提供低階 DB 能力，禁止提供任何業務查詢函式
所有業務查詢只能在 core/*

16.6 core 拆檔上限（寫死）

v1.x 的 includes/core/ 檔案數原則：不超過 10–12 個
超過必須先合併，不准繼續拆（避免 AI/人類讀不回來）

目前狀態與下一步（給新小玲立刻開工）
17.1 已定案（Active）

每人一帳號登入；登入後只看到自己範圍
Sessions 由同工預先建立（meeting_type + service_slot）
小組長點名：名單型（逐人）+ newcomers_count（不填名字）
招待 Headcount：多分項數字填報（venue/audience/mode）
Stats：雙來源並存 + 週/月/季/年比較（跨年）
架構硬規則：後台開發時必須符合未來前台後台可共用核心，不准重做
插件骨架/檔案架構已定案（見 16.x）

17.2 建議施工順序（不改）

Plugin 骨架 + 安裝建表（sessions / people / attendance_records / group_newcomer_counts / headcounts / leader_scope / usher_scope）
核心層先做：權限與範圍檢查、寫入與驗證、統計彙總與跨年規則（先把雷解在核心）
再做 wp-admin 後台頁面（Sessions、People、Headcount、Stats）全部只呼叫核心層
前台小組長：選場次 → 點名（逐人）+ newcomers_count → 整包送出（走同一核心）
招待填報：選場次 → 多列分項 → 整包送出（走同一核心）
Stats 曲線圖：先做後台版本（可用），未來要換前台儀表板只是換 UI，不改核心

需求沿革與決策紀錄（完整保留來龍去脈，但不污染現行規格）

（以下保留 v1.4 原文，仍有效）

18.1 v1.0 原始核心（仍有效）

獨立 plugin、與讀經打卡系統完全分離
每人一帳號、登入後只見自己範圍
人員層級：分堂/牧區/小區/小組 必須對齊
Sessions 同工預先建立
點名包含：出席 + 現場/線上
整包送出優先（穩定）

18.2 v1.1 擴充：特別聚會 + 跨年比較（仍有效）

在主日/禱告會/小組之外新增「特別聚會」
統計比較擴到月/季/年，且必須跨年份
週採 ISO week，避免跨年錯歸屬

18.3 已廢止：前台新增新朋友人名（被牧者更新推翻）

舊想法：小組長前台可新增新朋友姓名，並立刻納入點名
新決策（現行）：新朋友流動，前台只填 newcomers_count；正式名單由 People 建立/轉正
推翻原因：避免髒資料、重複人名、前台操作變慢、後續難清理

18.4 v1.3 納入：招待現場 Headcount（已成現行規格）

招待在每場聚會填報總人數與分項（含流動新朋友與未入小組者、線上渠道等）
與小組長名單型統計允許不一致
Stats 必須雙來源並存、可比較差異，但不強制對齊

18.5 v1.4 納入：必須支援未來前台後台（已成現行硬規則）

後台開發時就必須採「一套核心 + 多入口 UI」
未來前台後台只做介面，不得要求重做系統

18.6 v1.5 納入：檔案架構/資產/AJAX/邊界寫死（本次新增）

插件骨架檔案架構定案（16.x）
JS/CSS 引入以 filemtime 作版本號（防快取雷）
AJAX 作為可選薄封裝，核心仍以 PHP service layer 為主
時間口徑與 DB helper 邊界寫死，避免未來拆成兩套邏輯

結語（寫死）

以上為 v1.5 唯一 SSOT。
後續任何新增需求，都必須用「更新這份 SSOT」方式進版號，不允許另開新文件或叫人參考別份。
