# Meta Inbox

[![GitHub repo](https://img.shields.io/badge/GitHub-askmehta789%2FMessengerai-181717?logo=github)](https://github.com/askmehta789/Messengerai)

A shared inbox for all your Facebook Pages' Messenger chats, with AI auto-replies from the AI provider you choose (OpenAI / ChatGPT, Google Gemini, Anthropic Claude, or any OpenAI-compatible API such as DeepSeek or Groq).

- Connect any number of Facebook Pages with one "Login with Facebook".
- All conversations in one inbox, with a filter per page, unread, needs-human, ad replies and done.
- Shows which ad a customer clicked (`ad_id` and ad title) and gives that to the AI.
- The AI replies automatically using your business information. It answers in the customer's language (English, Nepali, Romanized Nepali).
- Human takeover: when you reply from this inbox or from Meta Business Suite, the AI pauses for that chat.
- If the AI can't answer, it tells the customer a person will reply and flags the chat **Needs human**.
- "✨ AI draft" button: the AI writes a reply that you can edit before sending.
- **Team & page access:** admins add team members as *Admin* (everything) or *Agent* (only the chats and products of the pages you tick).
- **Products & media:** save products with price, details, photos and videos. Send them in a chat with one click; the AI knows your products and sends their photos when a customer asks.
- Send any photo or video from your computer straight into a chat.
- **Orders:** create orders from a chat (or let the AI take them), track them on the Orders dashboard, and export them to Excel.
- Usage table with tokens per model, so you can estimate AI cost.
- Runs on normal shared hosting (PHP + MySQL) with no queue worker or Node.js.

---

## 1. Put it on Namecheap (Stellar Plus)

### 1.1 Create a subdomain
cPanel → **Domains** → **Create a new domain**: `inbox.yourdomain.com`
Set the **document root** to `meta-inbox/public` (untick "share document root").

### 1.2 PHP version
cPanel → **Select PHP Version** → choose **8.3 or newer**. In *Extensions*, make sure these are ticked:
`pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`, `tokenizer`, `xml`, `ctype`, `bcmath`.

In the same screen, open **Options** and set `upload_max_filesize` to **32M** and `post_max_size` to **64M**, so product videos up to Messenger's 25 MB limit can be uploaded.

### 1.3 Database
cPanel → **MySQL® Databases**:
1. Create a database, for example `youruser_inbox`.
2. Create a user with a strong password.
3. Add the user to the database with **ALL PRIVILEGES**.

### 1.4 Upload the files
Upload `meta-inbox-deploy.zip` with **File Manager** to your home folder (`/home/youruser/`) and **Extract** it. You should then have `/home/youruser/meta-inbox/`.

### 1.5 Configure `.env`
In File Manager, copy `meta-inbox/.env.production.example` to `meta-inbox/.env` and fill in:

| Setting | Value |
|---|---|
| `APP_URL` | `https://inbox.yourdomain.com` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from step 1.3 |
| `META_APP_ID`, `META_APP_SECRET` | from step 2 (you can fill these in later) |
| `META_VERIFY_TOKEN` | make up a long random text |
| `BUSINESS_NAME`, `CONTACT_EMAIL` | shown on your public privacy page |

### 1.6 Run the setup commands
Open cPanel → **Terminal** (or connect with SSH; Namecheap uses port 21098) and run:

```bash
cd ~/meta-inbox
php artisan key:generate --force
php artisan migrate --force
php artisan inbox:user you@yourdomain.com
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

`inbox:user` asks for your name and a password and creates your **admin** login. Add everyone else from the **Team** screen inside the app.

> If you change `.env` later, run `php artisan config:cache` again.

### 1.7 HTTPS
cPanel → **SSL/TLS Status** → run AutoSSL for `inbox.yourdomain.com`. Meta only works with `https://` URLs.

Open `https://inbox.yourdomain.com` and log in.

---

## 2. Create the Meta app

1. Go to <https://developers.facebook.com/apps> → **Create app**. Choose the **Business** type, or the use case for engaging customers on Messenger, and connect it to your Business portfolio.
2. **App settings → Basic**: copy the **App ID** and **App Secret** into `.env` (`META_APP_ID`, `META_APP_SECRET`), then run `php artisan config:cache`.
   Also fill in **Privacy Policy URL** and **User data deletion URL** with `https://inbox.yourdomain.com/privacy`.
3. Add the **Facebook Login** product. In its **Settings**, add this to *Valid OAuth Redirect URIs*:
   `https://inbox.yourdomain.com/pages/callback`
4. Add the **Messenger** product. Under **Webhooks**:
   - Callback URL: `https://inbox.yourdomain.com/webhook/meta`
   - Verify token: the same text as `META_VERIFY_TOKEN`
   - Subscribe to: `messages`, `messaging_postbacks`, `messaging_referrals`, `message_echoes`

   The inbox's **Pages** screen shows all these values ready to copy.
5. In the inbox, open **Pages** → **Connect pages with Facebook**, then select all your pages and allow the permissions.

### Testing vs. going live (important)

While the Meta app is in **Development mode**, it only receives messages from people who have a role on the app (you, plus anyone you add under **App roles**). Use this to test: message your page from your own account and watch the AI reply.

To receive and answer messages from **real customers**, the app needs **Advanced Access** to these permissions:

| Permission | Why |
|---|---|
| `pages_messaging` | Read and reply to Messenger chats |
| `pages_manage_metadata` | Subscribe pages to the webhook |
| `pages_show_list` | List your pages when connecting |
| `pages_read_engagement` | Read page info |
| `business_management` | Pages owned by a Business portfolio |

To get Advanced Access:
1. **Business Verification**: Business settings → Security Center → verify your business (registration document, address, phone).
2. **App Review**: request Advanced Access for the permissions above. For each one, describe how it's used ("we read customer messages sent to our own Pages and reply to them from our inbox, partly with AI") and upload a short screen recording: connecting a page, a message arriving, a reply being sent.
3. When approved, switch the app to **Live** mode.

This usually takes a few days to a few weeks.

---

## 3. Set up the AI

Open **AI settings**:

1. Choose the provider, paste your **API key**, and pick a model.
   - OpenAI: key from <https://platform.openai.com/api-keys>
   - Gemini: key from <https://aistudio.google.com/apikey>
   - Claude: key from <https://platform.claude.com/settings/keys>
   - OpenAI-compatible (DeepSeek, Groq, OpenRouter, ...): also set the base URL, for example `https://api.deepseek.com/v1`
2. In **Business information**, write what every page should know: shop name, contact, opening hours, tone, what to do when someone wants to order.
3. Open **Pages** and add each page's offers, delivery and payment details.
4. Open **Products** and add your products with price, description and photos. The AI reads this list for every reply.
5. Click **Send test message** to check that it works.

**Cost:** smaller models (for example `gpt-5-mini`, `gemini-2.5-flash`, `claude-haiku-5-5`) are much cheaper than the biggest models and are usually enough for shop questions. The usage table on the settings page shows tokens used per model.

### How the auto-reply behaves
- It waits a few seconds (*Wait before replying*) so a customer who sends "hi" and then "price?" gets one combined answer.
- It only answers within Messenger's 24-hour window after the customer's last message.
- When you or a teammate reply (in this inbox or in Meta Business Suite), the AI pauses for that chat for the number of hours you set. You can turn it back on with the switch in the chat header.
- If it can't help, it sends your hand-over message, flags the chat **Needs human**, and pauses.
- When a customer asks to see a product ("photo pathaunus"), it sends up to 3 photos/videos of that product from **Products**.

---

## 4. Team and page access

Open **Team** (admins only):

| Role | Can do |
|---|---|
| **Admin** | Everything: all pages, AI settings, connecting pages, team, all products |
| **Agent** | Only the chats of the pages you tick, and the products of those pages (plus shared products) |

- **Add team member:** name, email, password, role and pages. They can log in right away.
- **Edit:** change pages or role, reset the password, or untick *Can log in* to disable someone immediately.
- There is always at least one active admin; you can't remove your own admin access.
- The Pages screen shows which agents can see each page.

## 5. Products and media

Open **Products**:

- **Add product:** name, price, page (or *All pages* for products every page sells), description, and photos/videos (JPG, PNG, WEBP, GIF, MP4, MOV; up to 25 MB each, Messenger's limit).
- In a chat, click the **🛍 bag button** next to the reply box: click a photo to send it, **Send all** to send every photo, or **Add details to reply** to put the name and price into your message.
- Click the **🖼 picture button** to send a photo or video from your computer.
- Untick *Show in chats* to hide a product without deleting it.

Files are stored in `meta-inbox/public/media/`. Include this folder in your backups. Meta downloads the files from your site, so they only reach customers when the site runs on a public `https://` address (not on `localhost`).

---

## 6. Orders

**From a chat:** click **🧾 Create order** in the chat header (or in the customer panel). Name, phone and address are filled in from the chat notes when they're there. Pick products (or type a custom item), set quantity, delivery charge and payment, and optionally send the order summary to the customer in Messenger. The chat gets the *Ordered* label.

**By the AI:** when a customer confirms an order and has given name, phone and address, the AI saves it as a **Pending** order marked ✨ AI. It only uses products from your product list, never creates the same order twice, and leaves the delivery charge for your team to check.

**Orders dashboard** (Orders in the sidebar):
- Totals for the selected period: orders, sales (without cancelled and returned), average order, orders waiting to be confirmed, delivered.
- Orders-per-day chart for the last 14 days (hover a bar for the day's sales) and top products.
- Filter by period, page, source (AI / chat / manual) and status, or search by order number, name, phone or address.
- Change an order's status straight from the list: Pending → Confirmed → Shipped → Delivered (or Cancelled / Returned).
- **Export to Excel** downloads the orders matching your filters as an `.xlsx` file (one row per order, with items, totals, payment and notes). If the server lacks PHP's `zip` extension it downloads a `.csv` instead, which also opens in Excel.

Agents only see orders of their pages. Only admins can delete orders. Set your currency with `CURRENCY=Rs.` in `.env`.

## Updating

Upload the new files over the old ones (keep your `.env` and the `public/media` folder), then:

```bash
cd ~/meta-inbox
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Troubleshooting

- **Logs:** `meta-inbox/storage/logs/laravel.log`
- **Meta says the webhook couldn't be verified:** check that `META_VERIFY_TOKEN` matches, you ran `php artisan config:cache`, and the site opens over https.
- **A page shows "Not receiving messages":** click *Re-subscribe webhook* on the Pages screen; the error message says why.
- **AI doesn't reply:** check AI settings → *Send test message*, the AI switch on the page and on the chat, and the log file.

## Local development

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan db:seed --class=DemoSeeder   # sample chats
php artisan inbox:user
php artisan serve
php artisan test
```
