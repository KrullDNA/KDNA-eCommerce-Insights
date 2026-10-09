# Connecting Meta and Google Ads to Insights

This guide walks you through connecting your Meta (Facebook and Instagram) and Google Ads accounts to KDNA eCommerce Insights, so your ad spend comes in automatically every day.

You only need to do this once per ad account. Allow about 20 minutes for Meta and 30 minutes for Google. If anything here looks different on your screen, the platforms do move buttons around from time to time; the names of the settings stay much the same.

**What Insights reads:** daily spend, impressions, clicks, purchases (conversions) and purchase value for each campaign. It only ever **reads** your ad data. It cannot create, change or pause ads, and it never sees your payment details.

**Where your details are kept:** everything you paste in is stored on your own website, encrypted. It is never shown again on screen, not even to administrators, and is never sent anywhere except to Meta or Google themselves.

---

## Part 1: Meta (Facebook and Instagram ads)

Insights uses a "bring your own app" connection. You create a small private app inside your own Meta business account and give Insights a read-only key for it. Nobody else's app is involved, and you can switch access off at any time.

**You will need:**

- Admin access to your Meta business portfolio (Business Manager).
- Access to the ad account you want to connect.

### Step 1: Create a Meta app

1. Go to [developers.facebook.com/apps](https://developers.facebook.com/apps) and log in with the Facebook account you use for your business.
2. If asked, register as a developer (it is free and takes a minute).
3. Press **Create app**.
4. When asked what the app is for, choose **Other**, then choose the app type **Business**.
5. Give it a name such as "Insights reporting", and choose your business portfolio. Press **Create app**.
6. On the app dashboard, find **Marketing API** in the list of products and press **Set up**.
7. Copy the **App ID** shown at the top of the page. You will paste it into Insights later.

You do not need to submit the app for review, publish it or add a privacy policy. It only reads your own ad account.

### Step 2: Create a system user

A system user is a "robot" login that belongs to your business rather than to a person, so the connection keeps working if a staff member leaves.

1. Go to [business.facebook.com/settings](https://business.facebook.com/settings) (Business Settings).
2. In the menu, open **Users**, then **System users**.
3. Press **Add**. Name it "Insights", choose the **Employee** role and press **Create system user**.

### Step 3: Give the system user access

1. With the new system user selected, press **Assign assets**.
2. Choose **Ad accounts**, tick your ad account, and switch on **View performance** only. Press **Save changes**.
3. Press **Assign assets** again, choose **Apps**, tick the app you created in Step 1 and give it **Develop app** access (this is needed to generate the token). Press **Save changes**.

### Step 4: Generate the access token

1. With the system user still selected, press **Generate new token**.
2. Choose the app you created in Step 1.
3. When asked for an expiry, choose **Never**, so you do not have to repeat this.
4. In the list of permissions, tick only **ads_read**.
5. Press **Generate token**, then **copy the token straight away**. Meta only shows it once. It is a long string starting with "EAA".

Keep the token private. Anyone with it can read your ad results.

### Step 5: Find your ad account ID

1. Open [Ads Manager](https://adsmanager.facebook.com).
2. Look at the web address in your browser. The number after `act=` is your ad account ID, for example `act=1234567890` means `1234567890`.

### Step 6: Connect in Insights

1. In WordPress, go to **Insights**, then **Marketing**.
2. On the **Meta** card press **Set up**.
3. Paste the **App ID**, the **ad account ID** and the **system user access token**.
4. If your ad account spends in a different currency from your shop, enter the conversion rate (how much 1 unit of the ad account's currency is worth in your shop's currency).
5. Press **Save and test**. The card should show your ad account's name and **Connected**.
6. Choose how far back to bring spend in (for example **Last 90 days**) and press **Sync now**.

That is it. Insights now syncs Meta every morning at about 5am.

---

## Part 2: Google Ads

Google needs three things: a sign-in client from Google Cloud (so you can sign in with Google), a developer token from Google Ads, and the ID of your ad account.

**You will need:**

- The Google account you use for Google Ads.
- A **Google Ads manager account**. Developer tokens only come from manager accounts. If you do not have one, creating it is free at [ads.google.com/home/tools/manager-accounts](https://ads.google.com/home/tools/manager-accounts/). Link your ad account to it when asked.

### Step 1: Create a Google Cloud project

1. Go to [console.cloud.google.com](https://console.cloud.google.com) and sign in.
2. At the top, open the project list and press **New project**. Name it "Insights reporting" and press **Create**.
3. With the new project selected, search for **Google Ads API** in the search bar, open it and press **Enable**.

### Step 2: Set up the sign-in screen

1. In the menu, go to **APIs and services**, then **OAuth consent screen** (it may be called **Google Auth Platform** and **Branding**).
2. Choose **External** (or **Internal** if your business uses Google Workspace and only your own team will sign in). Press **Create** or **Get started**.
3. Fill in the app name ("Insights reporting"), your email as the support email and developer contact, then save.
4. Under **Audience** (or **Publishing status**), press **Publish app** and confirm.

Publishing matters. While an app is in "Testing", Google ends the sign-in after 7 days and the connection stops. You do not need Google's verification; when you sign in later you may see a "Google hasn't verified this app" screen. As it is your own app, press **Advanced**, then **Go to Insights reporting**.

### Step 3: Create the sign-in client

1. In Insights, go to **Marketing**, press **Set up** on the **Google Ads** card, and copy the **Redirect address** shown at the top (press **Copy**).
2. Back in Google Cloud, go to **APIs and services**, then **Credentials** (or **Clients**).
3. Press **Create credentials**, then **OAuth client ID**.
4. Choose the application type **Web application** and name it "Insights".
5. Under **Authorised redirect URIs**, press **Add URI** and paste the redirect address from Insights exactly as it is.
6. Press **Create**. Copy the **Client ID** (it ends in `.apps.googleusercontent.com`) and the **Client secret**.

### Step 4: Get a developer token

1. Sign in to your **Google Ads manager account** at [ads.google.com](https://ads.google.com).
2. Go to **Admin**, then **API Centre**.
3. Fill in the short application form. For "How will you use the API", something like "Reading our own campaign spend into our website's reporting dashboard" is right.
4. Accept the terms and copy the **developer token**.

New tokens start with **test account access** only. Apply for **Explorer access** (or **Basic access**) on the same page; Explorer is often granted automatically and is plenty for reading spend. Until it is granted, Insights shows "The developer token only has test account access".

### Step 5: Find your customer IDs

1. Open the Google Ads account you want to connect. The **customer ID** is the 10-digit number at the top of the page, such as `123-456-7890`.
2. If you reach that account through your manager account, also note the **manager account's** 10-digit ID (shown when you are in the manager account).

### Step 6: Connect in Insights

1. In **Insights**, **Marketing**, press **Set up** on the **Google Ads** card (or **Change details**).
2. Paste the **OAuth client ID**, **Client secret**, **Developer token** and **Customer ID**. Add the **Manager account ID** if you use one, otherwise leave it empty.
3. Press **Save and sign in with Google**. Google opens: choose the Google account that can see the ad account, and allow access.
4. You return to Insights with "Signed in with Google". The card should show **Connected**.
5. Choose how far back to bring spend in and press **Sync now**.

Insights now syncs Google Ads every morning at about 5am.

---

## How syncing works

- **Every morning at about 5am** (or twice a day, or only when you press Sync now, under the connection settings), Insights reads the **last 7 days** for each connected platform. Platforms keep adjusting recent figures for a few days, so re-reading a week keeps everything accurate.
- **Sync now** brings figures in straight away, and can reach back up to 12 months, for example when you first connect.
- **Nothing is counted twice.** Once a platform is connected, its spend from the first synced day onwards comes only from the connection. Insights will not let you add Meta or Google spend by hand or by CSV for those dates, and explains why. Any spend you had added for those days before connecting is replaced by the live figures, and Insights tells you how much it replaced.
- **If a sync fails**, nothing already saved is changed, the card turns to **Needs attention** with the reason in plain English, and the next sync tries again. Every sync is also recorded in **Settings, Data**.
- **Disconnecting** removes the saved keys and stops syncing. Spend already brought in is kept, because it is real spend; you can delete it from the spend entries list afterwards if you wish.

## If something goes wrong

| What Insights says | What to do |
| --- | --- |
| Meta says the access token is no longer valid | The token was reset, or the system user was removed. Repeat Part 1, Step 4 and paste the new token into **Change details**. |
| The token does not have permission to read this ad account | In Business Settings, check the system user has **View performance** on the ad account (Part 1, Step 3) and that the token was generated with **ads_read**. |
| Meta could not find that ad account | Check the ad account ID is the number after `act=` in Ads Manager, and that the system user has access to it. |
| The developer token only has test account access | Apply for Explorer or Basic access in the API Centre (Part 2, Step 4). |
| Google sign-in has expired or was removed | Press **Sign in with Google** again. If it keeps happening every 7 days, publish the app (Part 2, Step 2). |
| Google does not recognise the OAuth client ID or secret | Copy both again from Google Cloud, Credentials. |
| The Google account you signed in with cannot see this ad account | Fill in the manager account ID, or sign in with a Google account that has access to the ad account. |
| Google sign-in did not work (redirect_uri_mismatch) | The redirect address in Google Cloud must match the one shown in Insights exactly, including `http` or `https`. |
| The saved token can no longer be read | Your website's security keys (in wp-config.php) were changed, so saved keys cannot be unlocked. Paste them in again. |
| This ad account spends in a different currency | Enter the conversion rate in the connection settings so spend is counted in your shop's currency. |
| Could not reach Meta or Google | Usually temporary. If it keeps happening, ask your host whether the website can make outgoing connections. |

## Switching access off

- **Meta:** in Business Settings, System users, remove the system user or press **Revoke tokens**.
- **Google:** at [myaccount.google.com/permissions](https://myaccount.google.com/permissions), remove "Insights reporting".
- **In Insights:** press **Disconnect** on the card.
