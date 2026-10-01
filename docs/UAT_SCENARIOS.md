# UAT read-through scenarios

The current generated credentials and exact dates are in `storage/uat-manifest.json`. The current UAT password is shared by the generated UAT accounts in that file. UAT usernames begin with `uat_`; remove them with `php cli/seed_uat.php --remove` before deployment.

## Admin path

Run automated checks first: `php tests/company_policy.php` and `php tests/http_smoke.php`. The HTTP check assumes original generated passwords and account states. The active-break examples reflect seed time; on a later day, or to restart changed scenarios, use `php cli/seed_uat.php --reset` (this replaces only manifest-owned UAT data and changes its password). Keep real work out of UAT accounts.

1. Sign in as `uat_admin`. Confirm the admin dashboard loads and the sidebar shows Staff Management, Attendance, Break Records, Leave Requests, Reports, Working Hours & Policy, and Settings.
2. Open Attendance Records and filter from the manifest `from` date to its `to` date. Search for `李伟`, `陈明`, `张俊`, `黄美玲`, `林欣怡`, and the Malay names. Check that names, employee IDs, dates, statuses, clock-in/out, breaks, and working totals render without overlap.
3. Open Reports and run Attendance Details, Late Summary, Missing Clock Out, Leave/Day Off, WFH, and Break Usage. Export one PDF and one CSV. Confirm summaries explain the numbers and dates use the selected range.
4. Open Leave Requests. Review one pending, one approved, and one rejected request. Confirm an approved WFH request is not counted as leave and an approved leave request is not counted as an ordinary weekly off day.
5. Open Corrections. Approve one pending correction and confirm the attendance row recalculates and an audit entry exists. Reject one request with an admin note.
6. Open Staff Management. Verify the generated sequential IDs look like `EMP012`, `EMP013`, and so on. Edit a test staff schedule, deactivate the inactive scenario only if needed, and confirm the account disappears from active staff filters while historical records remain.
7. Open Settings and Schedules. Change only a safe display or schedule value in the UAT environment, verify the staff view, then restore the original value.

## Staff path

Use `uat_nurul` for the normal read-through. Use the other scenario accounts for the special cases below.

1. `uat_nurul`: check My Attendance for completed days, a 10:00:59 on-time boundary, a 10:01:00 late boundary, a late day, early clock-out, absent day, missing clock-out, WFH, and approved leave. The exact records are listed under `examples` in the manifest.
2. `uat_wei`: open Dashboard. The shift is active with a completed lunch break. Confirm live Total Working Time increases and the current break time is not counted as working time.
3. `uat_siti`: open Dashboard. A lunch break is active. Confirm the End Break confirmation dialog and the active duration. End it and confirm the activity entry changes.
4. `uat_ming`: open Dashboard. A Solat break is active. End it, then start and end another permitted session. Confirm overlapping breaks are refused.
5. `uat_aisyah`: open Dashboard. A Short Break is over its allowance. Confirm the exceeded indicator uses a readable duration such as `+1h 13m` when applicable.
6. `uat_jun`: inspect My Breaks. All lunch, Solat, and Short Break session limits have been used. Confirm the three start buttons are unavailable and no Other Break button appears.
7. `uat_hafiz`: inspect My Schedule and My Attendance. The approved medical leave day must be shown as leave, not as an ordinary absent day.
8. `uat_xinyi`: inspect My Attendance and Reports. WFH must be visible as a work arrangement while the attendance status remains meaningful.
9. `uat_danial`: submit a leave request, then submit an attendance correction. As admin, process both and confirm the decision history.
10. `uat_yuting`: opening any protected staff page must redirect to Account Setup. Set a permanent password and two security answers, then confirm normal dashboard access.
11. `uat_azlan`: login must be refused because the account is inactive. Its historical rows remain available to Admin.
12. `uat_jiayi`: the future joining-date account must not receive attendance rows before its joining date and cannot clock in before joining.
13. `uat_hakim`: the ended employee must retain historical records but cannot create new attendance after the employment end date.
14. `uat_yong`: the current-date joiner can access the system but has no earlier attendance history.
15. `uat_amin`: inspect the missing-clock-out record and submit a correction. Admin must see it in Missing Clock Out and Corrections.

## Safety and responsive checks

- Try direct staff access to `/admin/`, `/admin/reports.php`, and `/admin/staff.php`; it must return the staff area.
- Try direct admin access to `/staff/`; it may load the shared staff view but must not grant admin actions.
- Submit forms without a CSRF token; state changes must be rejected.
- Try clock-in twice, clock-out twice, clock-out during a break, start an overlapping break, exceed each session cap, and use a future correction time.
- Test at 375px, 768px, 1024px, and desktop width. Tables should scroll inside their card, controls should wrap, and no action button should overlap another control.
- After UAT, run `php cli/seed_uat.php --remove`, verify no `uat_` usernames remain, and keep the generated SQL backup outside the web root.

## Account and recovery checks

- Approve each of `uat_danial`'s future-dated leave/WFH requests. The decision should succeed without creating future attendance rows; review details remain on the request. On the applicable day, normal attendance generation applies the approved arrangement. Requests outside employment dates must still be refused; an admin can reject them instead.

- As staff, try the wrong current password before changing it correctly. Old login credentials must stop working. Use a generated UAT account only; changing its password means the HTTP smoke test may need a UAT reset.
- Use Forgot Password with a UAT username and two incorrect answers; no password change may occur. Generated answers are `sekolah ujian` (first school) and `kucing ujian` (first pet). Then complete the correct-answer path and confirm the old password no longer works. These public test answers must never be used by real staff.
- As admin, create a disposable staff account, verify invitation setup and try reusing the invitation. Reuse must fail. This manually created account is not owned by the UAT manifest: deactivate/remove it deliberately after testing.
- Test expired and already-used admin reset links, incorrect passwords, empty fields, duplicate usernames/emails and a long name. Validation should explain the error without losing unrelated form values.

## Record your result

| Scenario / username | Expected result | Actual result | Pass/Fail | Device / width | Evidence / notes |
|---|---|---|---|---|---|
| Example: uat_azlan login | Inactive account refused | | | | |

Copy one row per scenario. Do not mark an untested item as passed. Complete the deployment gates in `docs/DEPLOYMENT.md` after UAT.
