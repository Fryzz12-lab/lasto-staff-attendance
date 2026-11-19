# Phase 5A browser evidence notes

## Environment

- Local PHP server: `127.0.0.1:8092`, configured to use only the isolated database `staff_attendance_phase5a_disposable_28a0595f`.
- Database server: private local MariaDB data directory `.phase5a-mariadb-data`, TCP port `33120`; synthetic accounts only.
- Browser: local Chrome, isolated profile, viewport captures at CSS widths 320, 375, 390, 430, 768, 1024, 1280, and 1440 px.
- Application stylesheet and local Bootstrap returned successfully after setting the disposable server base path to `/`.
- Network access to external CDNs was denied in this environment. Bootstrap Icons, Flatpickr CDN assets, and Google Fonts therefore cannot be considered fully validated. Do not treat icon/font fallback details as intended production rendering.
- Task-created disposable database and local servers were shut down after capture. Screenshots retained here.

## Captures

- `staff_ready_{320,375,390,430,768,1024,1280,1440}.png`: synthetic employee not clocked in.
- `staff_lunch_390.png`: synthetic active lunch break.
- `staff_review_390.png`: synthetic completed shift; this fixture did not visibly demonstrate a staff-facing review-required state.
- `staff_leave_390.png`: leave/WFH request form and empty request list.
- `staff_profile_390.png`: location preference, profile, password, push preferences, display preference.
- `admin_today_{320,375,390,430,768,1024,1280,1440}.png`: admin daily attendance filters and synthetic staff rows.
- `admin_reports_{390,1280}.png`: report filters, discrepancy notice, summary metrics, attendance details.

## Observations

1. Admin daily attendance table at 320–430 px presents all desktop columns in a narrow table; synthetic staff names wrap to fragments, status cells collide with time/break/working cells, and the action column is clipped. At 1280 px the same data is readable.
2. Admin report has a separate mobile card rendering; 390 px cards and filters remain readable, but the page is very long with many records.
3. Staff ready state is clear and offers a prominent Clock In action on mobile. Desktop at 1280 px leaves substantial unused vertical space in this state.
4. Active lunch state puts break age, allowance, estimated end and End Break action in the first card; activity appears below. No location permission prompt or real device permission state was exercised.
5. Leave form stacks cleanly at 390 px and clearly communicates approval, but submit outcomes and validation-error states were not exercised.
6. Profile is a long multi-section page. Location preference and browser permission status are explicit; push preference controls are present. Native permission-denied, push recovery, and save-error states were not exercised.
7. The 390 px app header's menu icon is not visibly rendered under the external asset restriction; the source uses an accessible `Menu` label. This is a dependency limitation, not a conclusion about the normal connected rendering.
8. Screenshots show a dark theme. Both day and night themes exist in source; the alternate theme was not separately captured.

## Measurement limitation

The CDP screenshot captures are valid visual evidence. Automated DOM metric extraction returned an incomplete serialization and was discarded; no quantitative assertion about page scroll width or overflow beyond what is visibly apparent in screenshots is made here.
