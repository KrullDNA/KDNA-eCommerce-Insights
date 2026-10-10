=== KDNA eCommerce Insights ===
Contributors: krulldna
Tags: woocommerce, profit, analytics, reports, elementor
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 9.0
WC tested up to: 10.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows what a WooCommerce store actually made, not just what it sold. Profit, inventory, customer and marketing insights in a designed dashboard and Elementor widgets.

== Description ==

WooCommerce tells you what you sold. KDNA eCommerce Insights tells you what you made.

It takes every order and subtracts everything that money had to cover: product costs, payment fees, shipping, extra order costs, ad spend and overheads. What is left is your real profit, shown day by day, product by product and customer by customer.

**What you get**

* **Overview:** net revenue, net profit, orders, margin and a fifth figure of your choice, a performance chart against the previous period, stock status, and a hero card (top products, profit breakdown or goals tracker). Alerts tell you about products with no cost, low stock, loss-making orders and sync problems.
* **Profit & Loss:** a step-by-step waterfall from gross sales to net profit, a statement by month and where the money went.
* **Products:** units, revenue, cost, profit, margin and refund rate for every product and variation, with best and worst performers.
* **Customers:** new and returning customers, top customers, repeat purchase rate, lifetime value, cohorts and locations.
* **Inventory:** stock value, low stock, days of stock left, reorder dates and dead stock, with low stock emails.
* **Marketing:** ad spend by channel and campaign, ROAS, MER and cost per sale. Spend comes in from Meta and Google Ads every day, or can be entered by hand or imported from a CSV file.
* **Costs:** product cost prices (with history), payment fee rules, shipping cost rules, extra costs per order and overheads.
* **Tax & Reports:** a GST and BAS summary, a printable A4 report and a CSV export of every table.
* **Email digests:** a daily, weekly or monthly summary in your branding.
* **Elementor widgets:** Dashboard, Date Range, KPI Cards, Chart, Breakdown, Table and Hero Card, each with full style controls.
* **Your branding:** store name, logo, colours and font, with light and dark mode.

**Privacy**

* Figures are only ever shown to logged-in Administrators. Shop Managers, Editors and everyone else see nothing.
* Front-end pages with an Insights widget are hidden from search engines and from page caching, and visitors who are not Administrators never receive any figures, in the page or behind the scenes.
* Nothing is sent to KDNA or anyone else. The only outside connections are the read-only calls to Meta and Google Ads, and only when you connect them.
* Ad platform keys are stored encrypted and are never shown again after you save them.

== Installation ==

**Before you start**

* WordPress 6.5 or newer, PHP 8.1 or newer and WooCommerce 9.0 or newer.
* Elementor is optional. You only need it for the front-end widgets.

**1. Install and activate**

1. In wp-admin, go to Plugins > Add New > Upload Plugin.
2. Choose kdna-ecommerce-insights-1.0.0.zip and press Install Now.
3. Press Activate.

A new **Insights** item appears in the wp-admin menu.

**2. Let it read your order history**

When it is first activated, Insights works through every past order in the background, in small batches. A progress bar shows at the top of every Insights screen. You can use the dashboard while it runs; figures fill in as it goes. On a store with tens of thousands of orders this can take a while, and it carries on even if you close the page.

**3. Add your product costs**

Profit is only as good as the costs behind it, so this is the most important step.

* **One product at a time:** edit a product in WooCommerce and fill in the **Cost price** box (in the General tab, or the Inventory tab if you prefer; see Insights > Settings > Costs). Variations each have their own box.
* **Many at once:** go to Insights > Costs > Product costs, where every product is listed with an editable cost and margin.
* **From a spreadsheet:** on the same screen, press **Import CSV**, choose your file, check the preview and press Import.
* **Already using a cost plugin?** Insights can bring in costs from WooCommerce's own Cost of Goods field and popular cost of goods plugins from the same screen.

The Overview shows an alert while any products still have no cost.

**4. Check payment fees and shipping costs**

Go to Insights > Costs.

* **Payment fees:** Stripe and PayPal fees are read from each order automatically where the gateway records them. For any other payment method, add a rule, for example 1.75% + 0.30 per order.
* **Shipping costs:** if your shipping plugin records what you paid for postage, Insights can read it. Otherwise add a rule, such as a flat amount per order or per shipping method.
* **Extra costs:** add anything every order costs you, such as packaging or a gift card insert.

**5. Add overheads**

On Insights > Costs > Overheads, add your regular running costs, such as software subscriptions, rent or wages, monthly or yearly. They are spread evenly across each day so net profit reflects them.

**6. Connect your ad accounts (optional)**

Go to Insights > Settings > Marketing.

* **Meta (Facebook and Instagram):** create a private Business app and a system user in your own Meta business account, give the system user read-only access to your ad account, then paste the App ID, ad account ID and access token into Insights and press Test connection.
* **Google Ads:** create an OAuth client in Google Cloud, request a Google Ads developer token, then paste in the client ID, client secret, developer token and customer ID and press Connect to Google.
* **Other channels:** add spend by hand on the Marketing screen, or import a CSV export from any ad platform.

Spend is fetched every day. A detailed, step-by-step guide to both connections is available from KDNA.

**7. Settings**

Go to Insights > Settings. Each tab saves on its own and has a Reset to defaults button.

* **General:** which order statuses count as a sale, which date an order belongs to, how refunds are treated, and the starting date range.
* **Costs:** where the Cost price box sits, plus shortcuts to every cost screen and recalculation tools.
* **Marketing:** your ad channels and connections, and whether ad spend includes GST.
* **Tax:** your tax system (GST, VAT or sales tax), registration and reporting periods.
* **Branding:** store name, logo, colours, font and light or dark mode, with a live preview.
* **Hero card and Goals:** which hero card the Overview shows, and your monthly revenue, profit or orders goals.
* **Alerts and digests:** low stock emails and the email digest (who receives it and how often).
* **Data:** re-reading order history, the activity log, and whether to keep or delete your data if the plugin is ever removed.

**8. Elementor widgets (optional)**

In Elementor, the Insights widgets are in the **KDNA Tools** category.

1. Add an **Insights Dashboard** for the complete Overview in one widget, or build your own layout from **KPI Cards**, **Chart**, **Breakdown**, **Table** and **Hero Card**.
2. Add an **Insights Date Range** widget to let you change the dates of every widget on the page at once. Widgets follow it by default; any widget can use a fixed range of its own instead.
3. Use the **Preview state** control (Content tab) to design with sample data, or to style the loading, empty, restricted and error states. It only affects the editor.
4. Choose what other visitors see in **Who can see this**: a styled Restricted message with an optional log in button, or nothing at all.

After updating the plugin, go to Elementor > Tools > Regenerate CSS & Data, and clear any page cache.

== Frequently Asked Questions ==

= Who can see the figures? =

Only logged-in Administrators (users who can manage options). This applies to every Insights screen, every export and every widget.

= Does it slow my store down? =

No. Insights keeps its own small summary tables, updated in the background whenever an order is paid, changed or refunded. The dashboard reads these tables, not your orders, and nothing loads on your shop pages unless they contain an Insights widget.

= Does it work with High-Performance Order Storage (HPOS)? =

Yes. It supports both HPOS and the older order storage.

= What happens if I deactivate or delete the plugin? =

Deactivating keeps everything. Deleting keeps your data too, unless you switch on "Delete all plugin data on uninstall" in Insights > Settings > Data first. For safety, deleting the plugin always removes the saved Meta and Google Ads access keys, so you would reconnect those after reinstalling.

= Where are my ad platform keys kept? =

On your own site, encrypted with a key made from your site's security salts. They are never shown again after saving and never leave your site except to Meta or Google.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
