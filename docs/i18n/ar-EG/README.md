<!-- Source: README.md; SHA256: cf31b9f80d6c00020bbe08e80951d4de1c910793911d040ae7a66b54e8a2eaf0 -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_redfish

إضافة لـ Cacti 1.2 بتنقل استهلاك الطاقة للخادم ودرجة حرارة المدخل وحالة المراوح وصحة وحدات التغذية الكهربائية (PSUs) من وحدات التحكم في إدارة اللوحة الأم (BMCs) إلى Cacti كأجهزة ومصادر بيانات ورسوم بيانية عادية، باستخدام Redfish مع خيار بديل للقراءة فقط عبر IPMI وتجميع الطاقة على مستوى الراك. ويتولى Thold وMaint التنبيه عليها وكتم الإشعارات من خلال مصادر بيانات Cacti القياسية.

> **الحالة: قيد التطوير، لا يوجد إصدار رسمي حتى الآن (`0.1.0-dev`).** هذه الإضافة غير تابعة لمشروع Cacti. يتم تثبيتها على Cacti 1.2.31 أو أحدث مع PHP 8.2 أو أحدث، وتنفذ مُجمّع Redfish، وخزنة بيانات الاعتماد المشفرة، وتوفير أجهزة ورسوم Cacti، وبديل IPMI الاختياري للقراءة فقط، وتجميع طاقة الراك، وعرض نوافذ Maint الخاصة بـ Cacti، ونسخ مُجمّع البيانات البعيد ونقاط نهاية الوكيل، ومزود CasC وواجهة سطر الأوامر للمشغل، وقدرات MCP، وفحوصات Doctor التشخيصية. تختبر CI التجهيزات التجريبية وخادم DMTF Redfish Mockup Server (نماذج النظام الفرعي والوضع القديم فقط وHPE iLO Gen11) وThold v1.8.2 مع Maint v1.3؛ ولم يتم اعتماد أي جهاز BMC فعلي أو أسطول تجريبي حتى الآن. التصميم والأبحاث متوفرة في [docs/PLAN.md](../../PLAN.md) و[docs/research/](../../research/) و[docs/decisions/](../../decisions/).

## الإمكانات المنفذة

### المُجمّع الأساسي والخزنة وكائنات Cacti

- **دورة الحياة والمخطط.** التثبيت والترقية والتعطيل وإزالة التثبيت عبر واجهة برمجة إضافات Cacti (`Schema::VERSION = 3`، 12 جدولاً). يتم الاحتفاظ بالجداول عند إزالة التثبيت ما لم يتم إلغاء تحديد *Keep Plugin Tables on Uninstall*. نقاط الدخول قابلة للتحليل على PHP 8.0 وترفض إصدارات PHP الأقدم من 8.2 أو غياب `ext-openssl` برسالة خطأ واضحة.
- **السجل والصلاحيات.** نطاقات *Redfish View* (`redfish_health.php`) و*Redfish Operator* (`redfish_operator.php`) و*Redfish Admin* (`redfish_bmcs.php`, `redfish_credentials.php`)، بالإضافة إلى الإعدادات ضمن **Configuration > Settings > Redfish**. يدعم سجل BMC استيراد CSV (حيث يُشار إلى بيانات الاعتماد بالاسم؛ وتُرفض كلمات المرور داخل ملفات الاستيراد) وتصدير JSON. تتبع سجلات BMC وبيانات الاعتماد صلاحيات الأجهزة في Cacti.
- **خزنة بيانات الاعتماد المشفرة.** تُخزّن كلمات المرور مشفرة بخوارزمية AES-256-GCM في `plugin_redfish_secret`. يُحفظ المفتاح خارج قاعدة البيانات (`/etc/cacti/redfish-vault.key` افتراضياً) كـ 64 حرفاً سداسياً عشرياً (`openssl rand -hex 32`) أو 32 بايت خام، مملوكاً لمستخدم المستطلع (poller)، وغير قابل للقراءة من المستخدمين الآخرين، داخل مجلد لا يكتب فيه سوى root أو هذا المستخدم. وتُرفض عبارة المرور القابلة للطباعة المكونة من 32 حرفاً. **احفظ نسخة احتياطية من ملف المفتاح مع قاعدة البيانات**؛ بدونه ستضطر لإعادة إدخال كلمات المرور المخزنة.
- **TLS وعميل Redfish للقراءة فقط.** يكون التحقق من TLS مفعّلاً افتراضياً باستخدام مخزن CA للنظام أو حزمة CA لكل BMC أو تثبيت SPKI SHA-256؛ ويبقى خيار عدم التحقق لكل BMC ظاهراً كتحذير صحة وتحذير فحص Doctor. يسمح العميل فقط بـ `GET` بالإضافة إلى تسجيل دخول جلسة Redfish (`POST`) وتسجيل الخروج (`DELETE`)، مع خيار بديل Basic لكل BMC عند عدم توفر الجلسات. يفضل الاكتشاف موارد النظام الفرعي والمستشعرات الجديدة في Redfish ويعود إلى موارد `Power` و`Thermal` المهجورة عند الحاجة.
- **تنفيذ المُجمّع.** يبدأ `poller_redfish.php` من `poller_bottom`، ويشغّل عملية رئيسية واحدة لكل مستطلع عبر سجل عمليات Cacti مع عمليات عاملة قابلة للتكوين (`redfish_workers`)، ويحجز عقود إيجار لكل BMC، ويفرض ميزانية زمنية (`redfish_collector_budget`)، ويطبق تراجعاً أسياً، وينظف الجلسة السابقة الخاصة بالإضافة بعد أي توقف مفاجئ.
- **القوالب والأجهزة والرسوم البيانية.** يستورد `templates/redfish-templates.xml` حسب التجزئة وينسخ ثلاثة ملفات XML لاستعلامات بيانات خادم السكربت (`redfish_fans.xml`, `redfish_temperatures.xml`, `redfish_psus.xml`) إلى `<cacti>/resource/script_server/` دون استبدال الملفات التي عدّلها المستخدم. يتطلب **Create Device** و**Add Graphs** تأكيد خطة التوفير، ويربطان الاستعلامات بجهاز خادم مخصص أو ينشئان جهاز "Redfish BMC" تحت `.invalid` مع تعطيل فحوصات التوافر، ويربطان كل رسم بياني لـ Redfish بالرسوم البيانية لـ Redfish الخاصة بالجهاز عبر `graph_buttons`.
- **مدخلات خادم السكربت من الذاكرة المؤقتة فقط.** يقدم `scripts/ss_redfish_members.php` قراءات المراوح ودرجات الحرارة ووحدات التغذية الكهربائية لكل عضو، ويقدم `scripts/ss_redfish_summary.php` مقاييس "Redfish Node Summary" (الطاقة، ودرجة حرارة المدخل والقصوى، وعدد المراوح ووحدات التغذية المعطلة، وصحة العقدة، وعمر آخر قراءة سليمة). تقرأ استدعاءات خادم السكربت الذاكرة المؤقتة المحلية فقط ولا تتصل أبداً بأي BMC. تعيد القراءات المفقودة أو المنتهية الصلاحية أو الخاصة بالأعضاء المفقودين القيمة `U` (وليس `0` أبداً)، بينما يبلّغ `age` دائماً عن قيمة حتى تتمكن الحدود من اكتشاف أجهزة BMC الصامتة.

**عندما تتغير أعضاء BMC** (مثل تغيير مروحة أو تحديث البرنامج الثابت الذي يعيد تسمية المستشعرات)، شغّل **Add Graphs** مرة أخرى. تُحسب المروحة أو وحدة التغذية التي كانت موجودة سابقاً ثم أصبحت غائبة ضمن `fans_bad` أو `psus_bad` حتى يتم قبولها؛ أما الفتحة التي لم تكن مأهولة من قبل فلا تُحسب. يحتفظ الأعضاء المفقودون بمصادر البيانات والرسوم البيانية الحالية الخاصة بهم، والتي تقرأ `U` بعد ذلك حتى يتم حذفها يدوياً. يؤدي حذف جهاز Cacti إلى فك ارتباط BMC الخاص به وإزالة تعيينه في الراك مع الاحتفاظ بـ BMC والذاكرة المؤقتة؛ بينما يبقي حذف BMC أو إزالة تثبيت الإضافة على أجهزة Cacti ورسومها البيانية.

### بديل IPMI الاختياري

- **تفعيل اختياري لكل BMC.** معطّل افتراضياً (قيمة `protocols` الافتراضية هي `redfish`؛ وتفعيل بديل IPMI يضبطها إلى `redfish,ipmi`).
- **مُشغّل بديل محدود النطاق.** يعمل فقط عند فشل جمع Redfish بفئة عطل مؤهلة؛ ولا يعمل مطلقاً عند `AuthRejected` أو `CredentialUnavailable` أو `AddressRefused` أو `OperationRefused` أو `Deadline`.
- **تنفيذ آمن.** يستدعي `ipmitool` عبر `-I lanplus` باستخدام `proc_open()` بصيغة مصفوفة دون غلاف أوامر، وبمهلة زمنية محددة وبحد أقصى للمخرجات قدره 256 KiB. تُمرّر كلمات المرور عبر `IPMITOOL_PASSWORD` (`-E`) أو ملف مؤقت بصلاحية 0600 (`-f`) يُحذف داخل `finally`، وليس أبداً في معاملات سطر الأوامر argv.
- **قائمة مسموحة لأوامر القراءة فقط.** يُسمح فقط بخمسة أوامر فرعية للقراءة فقط: `dcmi power reading` و`sdr type Temperature` و`sdr type Fan` و`sdr type Power Supply` و`chassis status`. وتُرفض الأوامر التي تُجري تغييرات.
- **مخرجات موحدة وتحذير الأمان.** يطابق `IpmiNormalizer` القراءات مع نفس نموذج `Observation`/`Member`/`Reading`، ويُخرج `U` للمستشعرات المفقودة أو غير القابلة للتحليل. يؤدي تفعيل بديل IPMI إلى ظهور تحذير في صفحة الصحة وفي فحص Doctor `redfish.bmc_security` لتذكير المشغلين بتقييد UDP 623 على شبكة VLAN للإدارة.

### تجميع طاقة الراك

- **سجل الراكات.** يضيف الإصدار 3 من المخطط جدول `plugin_redfish_rack` (`(site_id, name)` فريد، و`power_budget_watts` اختياري، و`min_coverage_pct` بقيمة افتراضية `80`، وملاحظات) وجدول `plugin_redfish_rack_member` (تعيين راك واحد بحد أقصى لكل `host_id`، و`bmc_id` و`unit_position` اختياريان، ويُزال تلقائياً عند حذف جهاز Cacti).
- **مجاميع محمية بالتغطية.** يجمع `RackPowerSummary` قراءات `power_watts` الحديثة للأعضاء من الذاكرة المؤقتة المحلية ويحسب استهلاك الميزانية والهامش المتبقي. عندما تنخفض تغطية الأعضاء المبلّغين عن `min_coverage_pct`، تعيد `power_watts` و`utilization_pct` و`headroom_watts` القيمة `U` حتى لا تظهر أعطال المُجمّع أبداً كانخفاض في طاقة الراك.
- **مدخل خادم سكربت جاهز لـ Thold.** يعيد `scripts/ss_redfish_rack.php` (`ss_redfish_rack(<rack_id>)`) القيم `power_watts` و`power_budget_watts` و`utilization_pct` و`headroom_watts` و`members_total` و`members_reporting` و`coverage_pct` من الذاكرة المؤقتة المحلية فقط.

### التكامل مع Cacti Maint

- تطلق حدود Thold على مصادر بيانات Redfish تنبيهات ويكتمها Cacti Maint دون أي تعديل على أي من الإضافتين.
- يتحقق `MaintWindow` من جداول Maint النشطة لكل مضيف Cacti مرتبط (الجداول لمرة واحدة `mtype=1` والمتكررة `mtype=2`، بالإضافة إلى `plugin_maint_check_cacti_host()` عند تحميلها) ويضع علامة الصيانة النشطة في صفحة صحة Redfish (`[MAINT]`)، وفي مخرجات `cli/redfish.php health`، وفي قدرة `redfish-get-health`.

### مُجمّعات البيانات البعيدة

- يعلن `INFO` عن `remote_collect:1`. تقبل أجهزة BMC أي `poller_id >= 1`، ويجمع `poller_redfish.php --poller=N` فقط أجهزة BMC المفعلة المخصصة لهذا المستطلع.
- **خطاف `replicate_out`.** ينسخ فقط جداول الإعدادات غير السرية المخصصة للمستطلع البعيد المستهدف عبر `replicate_out_table()`: `plugin_redfish_bmc` والبيانات الوصفية لـ `plugin_redfish_credential` (دون نص مشفر) و`plugin_redfish_rack` و`plugin_redfish_rack_member`. لا يتم نسخ `plugin_redfish_secret`، وتظل ملفات مفاتيح الخزنة محلية تماماً على كل مستطلع.
- **خطاف `remote_agent`.** يوفر إجرائي القراءة فقط `redfish_health` و`redfish_discovery` اللذين يقبلان فقط المعاملين الرقميين `poller_id` و`bmc_id`. تُرفض المعاملات غير المتوقعة ولا تُعاد أي بيانات اعتماد أو أسرار.

### خطافات المنظومة (CasC وقدرات MCP وDoctor)

- **مزود CasC (`casc_register_providers`).** يسجل المزود `redfish` (إصدار المخطط `1`) مع تصدير YAML وXML بلغة PHP خالصة، وتحليل (مع رفض XXE)، وتحقق، وتخطيط الفروقات، وتطبيق. تظهر بيانات الاعتماد فقط كمراجع للخزنة (`vault:redfish:<name>`)؛ وتُرفض مفاتيح الأسرار المباشرة (`pass`, `secret`, `token`, `private_key`, `vault_key`, `auth_token`). يتطلب التطبيق قيمة `PLAN_HASH` SHA-256 مطابقة تماماً.
- **واجهة MCP / Abilities (`abilities_register`).** تسجل ثلاث قدرات للقراءة فقط محكومة بنطاق Redfish View (`redfish_health.php`) وصلاحيات أجهزة Cacti، ولا تعيد أي بيانات اعتماد:
  - `redfish-list-bmcs` (`poller_id` اختياري)
  - `redfish-get-rack-power` (`rack_id` اختياري)
  - `redfish-get-health` (`bmc_id` اختياري)
- **فحوصات Doctor (`doctor_register_checks`).** تسجل خمسة فحوصات تشخيصية محلية للقراءة فقط لا تتصل أبداً بأي BMC ولا تعيد أي مواد مفاتيح:
  - `redfish.schema`: حداثة إصدار المخطط
  - `redfish.vault_key`: إمكانية قراءة مفتاح الخزنة وصلاحياته
  - `redfish.collector_runs`: ميزانية مُجمّع المستطلع وحداثة القراءات
  - `redfish.bmc_security`: أجهزة BMC المفعلة ذات TLS غير المتحقق منه أو بديل IPMI
  - `redfish.rack_power`: الالتزام بميزانية طاقة الراك والحد الأدنى للتغطية

## واجهة سطر الأوامر CLI

شغّل من المجلد الجذر لـ Cacti باستخدام PHP 8.2 أو أحدث:

```sh
# Operator and CasC CLI (reads --file=PATH or stdin for validate, plan, and apply)
php plugins/redfish/cli/redfish.php export --format=yaml|xml
php plugins/redfish/cli/redfish.php validate [--file=PATH] [--format=yaml|xml]
php plugins/redfish/cli/redfish.php plan [--file=PATH] [--format=yaml|xml]
php plugins/redfish/cli/redfish.php apply [--file=PATH] [--format=yaml|xml] --confirm=<PLAN_HASH>
php plugins/redfish/cli/redfish.php health --format=text|json

# Collector entry point (normally started by poller_bottom)
php plugins/redfish/poller_redfish.php [--poller=N] [--force] [--debug]

# Rack power summary script server entry point
php plugins/redfish/scripts/ss_redfish_rack.php <rack_id>
```

## أدوار الوصول

| النطاق | الصلاحية |
| --- | --- |
| Redfish View | صفحات المراقبة الحالية. |
| Redfish Operator | المراقبة بالإضافة إلى صفحة العمليات وإقرارات الفرز الشخصية. |
| Redfish Admin | صلاحية المشغل بالإضافة إلى الإعدادات والإجراءات الإدارية الحالية. |

بعد نسخ ملفات الترقية، افتح صفحة Plugins كمسؤول لتسجيل النطاقات. خصص نطاق Operator صراحةً ضمن **Configuration > Users** أو مجموعات المستخدمين. تحافظ الترقيات على معرفات ومنح نطاقي View وAdmin الحالية. يرث وصول Admin وصول Operator، ويرث وصول Operator وصول View في كل طلب؛ ولا يضيف ذلك منحاً مخزنة للمستخدمين أو المجموعات. تتطلب إعدادات الإضافة أيضاً وصول Redfish Admin بالإضافة إلى صلاحية Settings في Cacti.

تحدد صلاحيات أجهزة Cacti الصفوف والإجراءات المتاحة لكل مستخدم؛ وتتطلب الكائنات غير المرتبطة مسؤولاً. يمكن للمشغلين إزالة تعليق الجمع عن BMC مسموح به للتشغيل المجدول التالي. تظل بيانات الاعتماد والإعدادات والربط والحذف والتغييرات الإدارية الأخرى في الصفحات الإدارية. تستمر الإقرارات فقط في جلسة المستخدم الحالية، لمدة ساعة واحدة بحد أقصى وفقط طالما بقيت الملاحظة نفسها حديثة. وتبطلها الملاحظات الجديدة أو المتغيرة. كما أنها لا تضع أبداً علامة الصحة على قياس فاشل ولا تكتم التنبيهات.

## لماذا

يمتلك كل خادم وحدة BMC تبلّغ عن حالة الطاقة والحرارة بشكل مستقل عن نظام التشغيل، ولذلك تظل تستجيب حتى عند توقف نظام التشغيل. تفيد هذه البيانات في متابعة السعة والتكلفة، والإنذار المبكر بتعطل مروحة أو وحدة تغذية كهربائية، وفي قرارات تحديد سقف الطاقة في الحوسبة عالية الأداء. والإضافة ليست حصرية للحوسبة عالية الأداء.

يغطي Cacti جزءاً من ذلك حالياً:

- **حزم SNMP من الشركات المصنعة.** يوفر فرع 1.2.x قوالب Dell iDRAC وHPE iLO التي تتطلب وكيل SNMP وتكون خاصة بكل مصنع؛ وقد وجد بحث المعلم 1 أن أياً منها لا يبلّغ عن استهلاك الطاقة بالواط ([docs/research/overlap.md](../../research/overlap.md)).
- **نوع بيانات اعتماد بدون مُجمّع.** يعرّف plugin_credentials بيانات اعتماد "IPMI / Redfish (BMC)" لكنه لا يقرأ أي BMC.
- **غياب العرض على مستوى الراك.** لا يحتوي جوهر Cacti على كائن راك؛ بل يوجد فقط حقل `location` نصي حر للجهاز.

تستطلع الإضافة أجهزة BMC خارج النطاق بالنسبة لدورة استطلاع Cacti على المستطلع الرئيسي أو مُجمّعات البيانات البعيدة، وتخزن القراءات الموحدة مؤقتاً، وتعرضها عبر استعلامات بيانات خادم السكربت وملخصات العقد وملخصات طاقة الراك. وهي تعيد استخدام الرسوم البيانية والصلاحيات وThold وMaint في Cacti، ولا تغيّر حالة BMC.

## النطاق والحالة

| المرحلة | النطاق | المعالم | الحالة |
|---|---|---|---|
| 0، البحث | مصفوفة المصنعين، تأكيد السلوك السابق على أجهزة BMC فعلية، تكلفة الاستطلاع، تجربة استعلام البيانات | [M1](https://github.com/somethingwithproof/plugin_redfish/milestone/1) | مكتمل، في انتظار التقاطات البرامج الثابتة لأجهزة BMC حقيقية |
| 1، المراقبة | سجل BMC، بيانات الاعتماد، TLS، مُجمّع Redfish، القراءات الموحدة، الأجهزة، الرسوم البيانية لكل فهرس، تنبيهات Thold، تكامل Maint، صفحة الصحة | [M2](https://github.com/somethingwithproof/plugin_redfish/milestone/2)، [M3](https://github.com/somethingwithproof/plugin_redfish/milestone/3)، [M4](https://github.com/somethingwithproof/plugin_redfish/milestone/4) | منفّذ؛ اعتماد الأسطول التجريبي للمعلم M4 قيد الانتظار |
| 2، ipmi | بديل IPMI (`lanplus`) للقراءة فقط عبر `ipmitool`، معطّل افتراضياً | [M5](https://github.com/somethingwithproof/plugin_redfish/milestone/5) | منفّذ |
| 3، الراكات | سجل الراكات، إجماليات طاقة الراك مع حد التغطية الأدنى، هامش الميزانية، `ss_redfish_rack.php` | [M6](https://github.com/somethingwithproof/plugin_redfish/milestone/6) | منفّذ |
| 4، البعيد والمنظومة | مُجمّعات البيانات البعيدة (`replicate_out`، `remote_agent`)، مزود CasC وCLI، قدرات MCP، فحوصات Doctor | [M7](https://github.com/somethingwithproof/plugin_redfish/milestone/7) | منفّذ |

خارج النطاق: ضبط حدود الطاقة أو أي إعدادات أخرى لـ BMC، التحكم بالطاقة، تحديث البرامج الثابتة، الوصول إلى وحدة التحكم، محرك تنبيهات خاص بالإضافة، والتكامل مع المجدول.

## أهداف التوافق

| المكون | الهدف |
|---|---|
| Cacti | 1.2.31 أو أحدث، دون تعديلات على الجوهر |
| قاعدة البيانات | MariaDB 10.2 أو MySQL 5.7 أو أحدث: يتم ضمان BMC واحد لكل جهاز بواسطة مفتاح فريد على عمود مولَّد |
| PHP | 8.2 أو أحدث؛ تبقى نقاط الدخول قابلة للتحليل على 8.0 حتى يتلقى وقت التشغيل غير المدعوم رفضاً بدلاً من واجهة Cacti معطلة |
| إضافات PHP | `ext-openssl` (مطلوبة من Cacti 1.2.31)؛ بدون `ext-curl` وبدون اعتماديات تشغيل Composer |
| أدوات اختيارية | `ipmitool` على المستطلعات التي تجمع أجهزة BMC مع تفعيل بديل IPMI |
| أجهزة BMC | Dell iDRAC وHPE iLO وLenovo XCC وOpenBMC حسب نطاق البرنامج الثابت؛ راجع [مصفوفة المصنعين](../../research/vendor-matrix.md) |
| Redfish | موارد النظام الفرعي والمستشعرات الجديدة أولاً، و`Power` و`Thermal` المهجورة كبديل |
| Thold، Maint | لا يتطلبان أي تعديلات (تختبر CI الإصدار Thold v1.8.2 وMaint v1.3) |

## التصميم

- [docs/PLAN.md](../../PLAN.md) هو الخطة الكاملة: المعمارية، والحقائق الأولية التي تعتمد عليها، ونموذج القياس، وحدود الأمان، وحسابات الأداء، والاختبارات، والمخاطر.
- [docs/decisions/](../../decisions/) يحتوي على سجلات القرارات المعمارية للاختيارات المتخذة بالفعل.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) يربط كل معرف متطلب بالبلاغ الخاص به.

```mermaid
flowchart LR
    BMC["أجهزة BMC: Redfish HTTPS / بديل IPMI lanplus اختياري"] --> C["المُجمّع على المستطلع الرئيسي أو البعيد"]
    C --> CACHE[("ذاكرة التخزين المؤقت للقراءات الموحدة")]
    CACHE --> SS["خادم السكربت: الأعضاء، ملخص العقدة، ملخص الراك"]
    SS -->|poller_output| T["Thold و Maint"]
    SS --> G["رسوم وصلاحيات Cacti"]
    CACHE --> ECO["CLI و CasC وقدرات MCP و Doctor"]
```

## الأمان

تتحكم بيانات اعتماد BMC في الإدارة خارج النطاق للخوادم. يُبقي التصميم الأسرار بعيداً عن سطور الأوامر والسجلات وتصديرات CasC وحمولات الوكيل البعيد واستجابات MCP وصفحات الويب؛ ويشفّر كلمات المرور المخزنة باستخدام AES-256-GCM عبر ملف مفتاح خزنة محلي لكل مستطلع؛ ويتحقق من TLS افتراضياً؛ ويشغّل `ipmitool` من مصفوفة معاملات صريحة مع أوامر فرعية مسموحة للقراءة فقط؛ ويستخدم حسابات BMC للقراءة فقط. راجع [docs/PLAN.md](../../PLAN.md#security) و[SECURITY.md](../../../SECURITY.md).

## دليل المشغل

راجع [docs/operator-guide.md](../../operator-guide.md) لمعرفة متطلبات التثبيت المسبقة، وإعداد الخزنة وTLS، وتسجيل BMC، وإنشاء الرسوم البيانية، وفترات التنبيه، والنسخ الاحتياطي، والتعامل مع الأعطال. لا يعلن الدليل دعم أي خط برامج ثابتة لمصنع فعلي حتى يجتاز التقاط منقح من ذلك البرنامج الثابت مجموعة الاختبارات.

## التطوير والاختبار

تقوم CI (`.github/workflows/plugin-ci-workflow.yml`) بفحص صيغة نقاط الدخول على PHP 8.0، وتشغّل مجموعات Pest للوحدة (`phpunit.xml`) وتكامل دورة الحياة (`phpunit-integration.xml`) وPHPStan مستوى 9 عبر PHP 8.2 إلى 8.5 مقابل Cacti `release/1.2.31` و`1.2.x`، وتتحقق من الرفض على PHP 8.1، وتختبر خادم DMTF Redfish Mockup Server (نماذج النظام الفرعي والوضع القديم فقط وHPE iLO Gen11) وThold v1.8.2 / Maint v1.3 بشكل كامل، وتفحص كتالوجات gettext ومسارات العمل وتراخيص REUSE.

الفحوصات المحلية قبل الدفع (push):

```sh
sh tests/lint-workflows.sh
sh locales/build_gettext.sh --check
CACTI_PATH=/path/to/cacti phpstan analyse -c phpstan.neon --no-progress
```

شغّل Pest باستخدام `--configuration=phpunit.xml` لاختبارات الوحدة وقواعد الكود المصدري، أو `--configuration=phpunit-integration.xml` مع `CACTI_PATH` و`REDFISH_TEST_DATABASE=disposable` مقابل قاعدة بيانات Cacti مؤقتة لاختبارات تكامل دورة الحياة.

## كيفية تتبع العمل

- تتبع **المعالم** معالم الخطة. ولكل معلم بلاغ ملحمي يسرد البلاغات الفرعية وأدلة الإنجاز.
- تُصنّف **البلاغات** بالوسوم: `type: requirement`، `type: research`، `type: decision`، `type: epic`. تحدد بلاغات المتطلبات متطلبات مرقمة وقابلة للاختبار (`REQ-AREA-NNN`) ومعايير القبول.
- تحمل **الوسوم** أيضاً `area:` و`phase:` و`priority:` و`status:`. راجع [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- تعرض [لوحة المشروع](https://github.com/users/somethingwithproof/projects/12) جميع البلاغات عبر المعالم.

## المساهمة

اقرأ [CONTRIBUTING.md](../../../CONTRIBUTING.md). تتطلب الإيداعات توقيع DCO (sign-off). تجري مناقشة التصميم في البلاغات؛ وتحمل القرارات المفتوحة الوسم `type: decision`. الأدلة المأخوذة من أجهزة BMC حقيقية هي المساهمة الأكثر فائدة الآن؛ احجب العناوين والأرقام التسلسلية وبيانات الاعتماد قبل نشر أي التقاط.

## الترخيص

رخصة GNU العمومية الإصدار 2.0، نفس رخصة Cacti وإضافاته. راجع [LICENSE](../../../LICENSE).
