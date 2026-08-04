| Field | Value |
|---|---|
| **Title** | SIPETA Testing Standards |
| **Purpose** | Define the testing strategy for every feature. No feature is complete without testing. |
| **Scope** | Unit, integration, manual, OCR, export, backup, restore, UI, Playwright. |
| **Version** | 1.1.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/database.md`, `.ai/ocr.md`, `.ai/coding.md`, `.ai/filament.md` |

---

# SIPETA Testing Standards

## 1. Testing Priority

1. Critical business logic
2. Database integrity
3. OCR pipeline
4. Export
5. Backup & restore
6. UI workflows

## 2. Test Pyramid

- **Unit tests** — services, actions, enums.
- **Feature tests** — controllers, Form Requests, Filament Resources.
- **Integration tests** — database operations, file storage.
- **Manual tests** — UI flows, OCR on real images.
- **End-to-end tests** — Playwright (UI only).

## 3. Manual Testing Checklist

For every feature verify:

- Feature works.
- Validation works (Form Request).
- Error messages are friendly in Bahasa Indonesia.
- Database updated correctly.
- No duplicated data.
- No unexpected exceptions.

## 4. CRUD Testing

For each entity (KK, Penduduk, Settings):

- Create.
- Read.
- Update.
- Status change.
- Delete (only for invalid records).
- Verify database consistency.

## 5. Search Testing

Verify:

- Name (partial).
- NIK (exact).
- KK Number (exact).
- Search returns accurate results within 500 ms.

## 6. Filter Testing

Verify each filter individually:

- RT
- RW
- Lingkungan
- Occupation
- Religion
- Gender
- Education
- Status
- Exact Age
- Age Range

Then test combined filters (AND semantic).

## 7. OCR Testing

Test with:

- Clear KK photo.
- Rotated photo (≤ 15°).
- Dark photo.
- Low-resolution photo (≥ 800×600).
- Multiple residents per KK.

Verify:

- Extracted text matches expected within tolerance.
- Parsed fields are correct.
- Confidence scores are reported.
- Duplicate detection warns.
- Manual correction flow works.
- **OCR never saves automatically.**

## 8. Export Testing

Test:

- PDF.
- Excel.
- CSV.

Verify exported rows = filtered rows.

## 9. Backup Testing

- Create backup.
- Verify ZIP contains:
  - Database dump.
  - KK photos.
  - Settings.
- Test restore on a copy.
- Verify offline restore works.

## 10. Restore Testing

- Restore from a valid ZIP.
- Verify data is restored.
- Verify photos are restored.
- Verify settings are restored.
- Restore from an invalid ZIP — verify graceful error.

## 11. Dashboard Testing

Verify:

- Active population count.
- Moved count.
- Deceased count.
- Charts (per RT, per Lingkungan, per Pekerjaan).
- Cache invalidation.

## 12. Settings Testing

- Singleton row enforcement.
- Logo upload.
- Backup path update.

## 13. Performance Testing

- Search at 50K records (≤ 500 ms).
- Dashboard render (≤ 1 s).
- Filter change (≤ 800 ms).
- OCR per image (≤ 10 s).
- Export 10K rows (≤ 30 s).

## 14. Playwright Rules

Use Playwright only when UI changes are required.

Required scenarios:

- Login.
- Search.
- Add resident.
- OCR review.
- Export.
- Backup.

After every test:

- Delete screenshots.
- Delete videos.
- Delete traces.
- Delete snapshots.
- Delete temporary folders.

Workspace must remain clean.

## 15. Bug Severity

| Severity | Example |
|----------|---------|
| Critical | Data loss. Corrupted backup. Wrong resident status. |
| High | OCR mapping failure. Wrong export. Filter failure. |
| Medium | UI issues. |
| Low | Cosmetic issues. |

## 16. Completion Checklist

A feature is complete only if:

- Tested.
- No critical bug.
- Documentation updated.
- `docs/CHANGELOG.md` updated.
- `docs/FEATURES.md` status updated.
- Temporary Playwright artifacts removed.

## 17. Test Data

- Use factories (`database/factories/`) for unit and feature tests.
- Use SQLite `:memory:` for testing, with schema mirroring MySQL.
- For OCR tests, use a committed set of `tests/Fixtures/kk/*.jpg`.

## 18. Implementation Notes

- PHPUnit is the test runner.
- Tests run via `php artisan test`.
- CI runs on every push.

## 19. Future Improvements

- Add visual regression testing.
- Add contract tests for backup format.
- Add load testing for OCR pipeline.
