<?php
declare(strict_types=1);

if (!defined('APP_ENV')) { http_response_code(404); exit; }

// Short reading layer for staff. The full translated policy remains in policy_content.php.
return match (lang_current()) {
    'ms' => [
        'by_service' => 'Kelayakan mengikut tempoh perkhidmatan',
        'conditions' => 'Syarat penting',
        'legal' => 'Lihat butiran undang-undang',
        'legal_hide' => 'Sembunyikan butiran undang-undang',
        'under_2' => '< 2 tahun',
        'two_to_5' => '2 – 5 tahun',
        'five_plus' => '≥ 5 tahun',
        'days' => 'hari',
        'holiday_compulsory' => '5 cuti wajib',
        'holiday_gazetted' => '6 cuti diwartakan yang lain',
        'notes' => [
            'annual' => [
                'Tidak termasuk hari rehat dan cuti am berbayar.',
                'Pekerjaan tamat sebelum 12 bulan: cuti berkadar mengikut bulan perkhidmatan yang lengkap.',
            ],
            'holiday' => [
                'Cuti tambahan yang diisytiharkan di bawah Akta Hari Kelepasan 1951 juga terpakai.',
                'Hari ganti mungkin terpakai jika cuti bertindih dengan hari rehat atau cuti am berbayar lain.',
            ],
            'medical' => [
                'Pemeriksaan oleh doktor berdaftar yang dilantik; pilihan lain terpakai jika doktor itu tidak tersedia secara munasabah. Pengesahan doktor gigi juga diiktiraf.',
                'Maklumkan atau cuba memaklumkan majikan dalam 48 jam dari permulaan cuti sakit.',
            ],
            'hospital' => [
                'Berasingan daripada cuti sakit biasa; sehingga 60 hari terkumpul, tidak semestinya berturut-turut.',
                'Doktor berdaftar atau pegawai perubatan mesti mengesahkan keperluan hospitalisasi. Kemasukan sebenar ke hospital tidak semestinya diperlukan.',
            ],
            'paternity' => [
                'Untuk pekerja lelaki berkahwin dengan sekurang-kurangnya 12 bulan perkhidmatan bersama majikan yang sama.',
                'Maklumkan majikan 30 hari sebelum jangkaan kelahiran, atau seawal mungkin selepas kelahiran.',
                'Termasuk hari rehat dan cuti am; terhad kepada lima kelahiran.',
            ],
            'maternity' => [
                '98 hari berturut-turut termasuk hari rehat dan cuti am.',
                'Elaun bersalin mempunyai syarat kelayakan yang berasingan daripada kelayakan cuti.',
                'Maklumkan jangkaan kelahiran dan tarikh mula cuti dalam tempoh 60 hari sebelum jangkaan bersalin.',
            ],
            'internship' => [
                'Selaraskan cuti dengan penyelia dan universiti atau penyedia latihan.',
                'Hak pekerjaan berkanun yang terpakai kekal berkuat kuasa.',
            ],
        ],
    ],
    'zh' => [
        'by_service' => '按服务年限计算',
        'conditions' => '重要条件',
        'legal' => '查看法律详情',
        'legal_hide' => '隐藏法律详情',
        'under_2' => '< 2 年',
        'two_to_5' => '2 – 5 年',
        'five_plus' => '≥ 5 年',
        'days' => '天',
        'holiday_compulsory' => '5个强制假日',
        'holiday_gazetted' => '另外6个宪报假日',
        'notes' => [
            'annual' => [
                '不包括休息日和带薪公共假日。',
                '雇佣关系在满12个月前结束：按已完成的服务月份比例计算。',
            ],
            'holiday' => [
                '《1951年假日法令》宣布的额外假日也适用。',
                '假日与休息日或其他带薪公共假日重叠时，可能适用补假规则。',
            ],
            'medical' => [
                '由指定注册医生检查；如无法合理获得其服务，可使用其他合资格医生。牙医证明也获承认。',
                '病假开始后48小时内通知或尝试通知雇主。',
            ],
            'hospital' => [
                '与普通病假分开计算；累计最多60天，无须连续。',
                '须由注册医生或医务官证明需要住院；经证明需要住院时，实际入院并非必需。',
            ],
            'paternity' => [
                '适用于已婚男性员工，且在休假开始前已为同一雇主服务至少12个月。',
                '预产期至少30天前通知雇主，或在出生后尽早通知。',
                '包括休息日和公共假日；最多适用于五次分娩。',
            ],
            'maternity' => [
                '连续98天包括休息日和公共假日。',
                '产假津贴的资格条件与产假权利分开计算。',
                '在预产期前60天内通知雇主预产期和计划开始休假的日期。',
            ],
            'internship' => [
                '请与主管及大学或培训机构协调休假。',
                '适用的法定雇佣权益仍然有效。',
            ],
        ],
    ],
    default => [
        'by_service' => 'Entitlement by service',
        'conditions' => 'Key conditions',
        'legal' => 'View legal details',
        'legal_hide' => 'Hide legal details',
        'under_2' => '< 2 years',
        'two_to_5' => '2 – 5 years',
        'five_plus' => '≥ 5 years',
        'days' => 'days',
        'holiday_compulsory' => '5 compulsory holidays',
        'holiday_gazetted' => '6 other gazetted holidays',
        'notes' => [
            'annual' => [
                'Rest days and paid public holidays are excluded.',
                'Employment ends before 12 months: leave is prorated by completed months of service.',
            ],
            'holiday' => [
                'Additional holidays declared under the Holidays Act 1951 also apply.',
                'Substitute-day rules may apply when a holiday overlaps a rest day or another paid public holiday.',
            ],
            'medical' => [
                'Examination is by an appointed registered doctor; alternatives apply if that doctor is not reasonably available. Dental certification is also recognised.',
                'Tell or try to tell the employer within 48 hours of sick leave starting.',
            ],
            'hospital' => [
                'Separate from ordinary medical leave; up to 60 days in total, not necessarily consecutive.',
                'A registered doctor or medical officer must certify that hospitalisation is needed. Actual admission is not always required.',
            ],
            'paternity' => [
                'For a married male employee with at least 12 months of service with the same employer.',
                'Notify the employer 30 days before the expected birth, or as early as possible after birth.',
                'Includes rest days and public holidays; limited to five confinements.',
            ],
            'maternity' => [
                'The 98 consecutive days include rest days and public holidays.',
                'Maternity allowance has separate eligibility conditions from maternity leave.',
                'Notify the employer of the expected birth and planned leave start within 60 days before the expected birth.',
            ],
            'internship' => [
                'Coordinate leave with the supervisor and university or training provider.',
                'Applicable statutory employment rights remain in place.',
            ],
        ],
    ],
};
