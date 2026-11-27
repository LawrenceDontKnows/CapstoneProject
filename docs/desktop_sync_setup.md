# GradeView Desktop Sync — Setup

Railway stays the central storage. A small Windows helper downloads a working
copy, opens it in Excel, and uploads saved changes back. Compute always uses the
latest confirmed server copy.

## 1. Database (one time)
Run `database/add_sync_devices.sql` on the Railway MySQL database
(Railway → MySQL → Data → Query, or phpMyAdmin). This creates `sync_devices`.

## 2. Railway volume (required)
Uploaded workbooks live on the container disk and are lost on redeploy unless a
volume is mounted:
- Railway → your service → **Settings → Volumes** → add a volume.
- Mount path: `/var/www/html/uploads/grade_imports`
Redeploy after adding the volume. Existing files must be re-imported after the
volume is first mounted.

## 3. Get a device token
In GradeView: **Manage Grade → Connect desktop helper**. Copy the token (shown once).

## 4. Windows helper
1. Install Python 3.9+ (python.org). Optional tray icon: `pip install pystray pillow`.
2. Copy `desktop_helper/gradeview_helper.py` to the teacher's PC.
3. Run it once — it creates `gradeview_helper.json` next to the script. Edit it:
   ```json
   {
     "server": "https://your-app.up.railway.app",
     "token": "PASTE_DEVICE_TOKEN",
     "port": 8765
   }
   ```
4. Run `python gradeview_helper.py`. It listens on `http://127.0.0.1:8765` and
   shows a tray icon (Synced / Syncing / Error).
   - To auto-start, put a shortcut to the script in
     `shell:startup` (Win+R → `shell:startup`).

## 5. Teacher workflow
1. Import an Excel workbook.
2. Click **Open** — the helper downloads it and launches Excel (editable).
3. Edit and press **Ctrl+S**.
4. The helper waits until the file stops changing, then uploads (≈2–5 s).
   Tray shows **Synced**.
5. Click **Compute** to update grades from the server copy.

## Notes
- The helper never uploads while Excel is still writing (stability check) and the
  API validates the `.xlsx` (ZIP + `xl/workbook.xml`) before replacing the file.
- Uploads are atomic (temp file + rename) and use a `base_sha1` conflict check:
  if the server copy changed since you opened it, re-open to get the latest.
- If **Open** shows a connection error, the helper is not running or `port`
  differs from 8765.