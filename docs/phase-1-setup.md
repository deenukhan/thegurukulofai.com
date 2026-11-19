# Phase 1 setup: contact store

What Deenu does by hand. None of it needs a secret in chat or in the repo.

Steps 1, 2, 3 and 6 are safe to do before the code goes live: the current code
ignores the new settings and the extra Razorpay event. Steps 4 and 5 come after "push it".

---

## 1. Create the database (hPanel)

1. Log in to hPanel. Go to **Websites**, then click **Manage** next to thegurukulofai.com.
2. In the left menu, open **Databases**, then **Management**.
3. Under **Create a New MySQL Database and Database User**, fill in:
   - **MySQL database name:** `tgai`
   - **MySQL username:** `tgai`
   - **Password:** click the generate button. Save the password in your password manager.
4. Click **Create**.
5. In the list below the form, note the full names. They start with `u886151741_`, for example `u886151741_tgai`.

## 2. Make the admin password hash (on your Mac)

Only the hash goes on the server, never the password itself.

1. Open **Terminal** on your Mac.
2. Paste this line and press Return:

   ```bash
   php -r 'echo password_hash(readline("New admin password: "), PASSWORD_DEFAULT), PHP_EOL;'
   ```

3. Type a new password you don't use anywhere else, then press Return. Save it in your password manager too.
4. Copy the line it prints. It starts with `$2y$`.

## 3. Add both to the private config

1. In hPanel, open **Files**, then **File Manager**.
2. Open the `private` folder, the one that holds `razorpay-config.php`.
3. **Make a backup first.** Right-click `razorpay-config.php`, choose **Copy**, and name the copy `razorpay-config.backup.php`.
4. Open `razorpay-config.php` to edit it. Find the line that starts with `'sent_dir'`.
5. Just **above** that line, paste this block. Then replace the four values:

   ```php
       'db' => [
           'host'     => 'localhost',
           'name'     => 'u886151741_tgai',
           'user'     => 'u886151741_tgai',
           'password' => 'THE DATABASE PASSWORD FROM STEP 1',
           'keep_unpaid_days' => 90,
       ],
       'admin' => [
           'password_hash' => 'THE $2y$ LINE FROM STEP 2',
       ],
   ```

   Keep the **single quotes**. Double quotes would break the hash, because it contains `$` signs.
6. Save the file.

If anything here has a typo, checkout still works: the contact store just stays off.
A broken bracket or quote is different. That stops the whole checkout. So in step 4
below, open the admin page straight away. If it shows an error, put the backup back.

## 4. After the code is live: check the admin page

1. Open `https://thegurukulofai.com/api/admin/`.
2. Sign in with the password from step 2.
3. You should see an empty list. It fills up as people check out, and once the cron job in step 5 has run.

Five wrong passwords lock the page for 15 minutes. It logs you out after 30 minutes idle.

Optional second lock: in hPanel, **Advanced**, then **Password Protect Directories**, and protect `public_html/api/admin`.

## 5. After the code is live: add the nightly job

1. In hPanel, open **Advanced**, then **Cron Jobs**.
2. Choose **Custom**.
3. **Command to run:**

   ```text
   /usr/bin/php /home/u886151741/domains/thegurukulofai.com/public_html/api/razorpay/cron/reconcile.php
   ```

4. Schedule: minute `30`, hour `21`, and `*` for day, month and weekday. On a server set to UTC, that is 3 AM IST.
5. Click **Save**.

The first run fills in every order since the 8 Nov launch by itself. After it runs,
click **View Output** next to the job. You should see a few lines of counts, with no
names or emails.

## 6. Tell Razorpay about failed payments

1. In the Razorpay Dashboard (Live mode), open **Account & Settings**, then **Webhooks**.
2. Click the webhook that points at `https://thegurukulofai.com/api/razorpay/webhook.php`, then **Edit**.
3. Under **Active Events**, keep `order.paid` ticked and also tick `payment.failed`.
4. Leave the secret as it is. Click **Save**.

Without this, failed tries still show up, but only after the nightly job runs.
