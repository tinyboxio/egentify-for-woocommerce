=== AI Chatbot & Helpdesk Agent for WooCommerce by Egentify ===
Contributors: egentify
Tags: woocommerce, chatbot, live chat, customer support, helpdesk
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chatbot, live chat, and help desk for WooCommerce. Answers product questions, tracks orders, and handles email and tickets, 24/7.

== Description ==

**Turn your WooCommerce store into a 24/7 support team that never sleeps.** Egentify adds an AI customer support agent (an AI chatbot) to your storefront that answers product questions, tracks orders, and resolves common issues in plain language, using your real products, pages, and policies so its answers stay accurate.

Today's shoppers expect instant answers, and when they have to wait, they leave. Egentify replies in seconds, day or night, so customers get help the moment they need it and your team stops drowning in the same repetitive questions. The result is happier customers, faster resolutions, and fewer lost sales.

And you are always in control. From your Egentify dashboard you can watch live chats as they happen, jump in to take over any conversation, reply to customers yourself, and manage support tickets, all in one place. The AI handles the routine, so you can focus on the conversations that matter.

**What your customers get**

* Instant answers to product and order questions, any time of day
* Order tracking and product search right inside the chat
* Natural, conversational help instead of clunky menus

**What you get**

* An AI chat widget on your storefront, matched to your brand: color, position, welcome message, and quick-reply buttons
* A live dashboard to monitor conversations, take over and reply to any chat, and manage support tickets in a built-in help desk
* Live chat, email, and AI-powered ticketing included, with voice support added on paid plans
* Answers grounded in your real catalog and store content, so the AI stays accurate
* Less time on repetitive questions, more time for the customers who need you

**Answers grounded in your real store data**

Egentify does not guess. It reads your actual WooCommerce catalog, product details, pages, and store policies to answer questions, and it can look up a customer's order to give real tracking and status updates right inside the chat. You can also add a supplemental knowledge base for things the AI would not otherwise know, like a current sale or active coupon codes. The result is accurate, on-brand answers instead of the vague replies shoppers expect from a generic bot.

**Stay in control with live takeover**

Every conversation streams to your Egentify dashboard in real time. Watch the AI work, and step in whenever you like: take over a chat, reply to a customer yourself, or follow up later as a support ticket. You decide how much the AI handles and exactly when a human takes the wheel.

**Make it yours**

Match the chat widget to your storefront in minutes. Set your brand color, choose where it sits and how far in from the edges, round the corners to taste, write your own welcome message, and add quick-reply buttons that point customers to the questions you most want to answer first. Let it appear automatically on every page, or place it exactly where you want with the `[egentify_chat_widget]` shortcode.

**More than chat: email, tickets, and voice**

Chat is only the start. Egentify also answers over email and can turn conversations into support tickets the AI helps create and manage, all included from the free plan. As you grow, paid plans add voice support, so phone calls sit alongside your chats in one dashboard.

**How it works**

1. Connect your WooCommerce store to Egentify in a couple of clicks.
2. Customize the chat widget to match your brand, welcome message, and quick-reply buttons.
3. Go live. The AI starts answering instantly, and you can monitor or step into any conversation from your dashboard.

**Your data stays yours**

The plugin sends nothing to Egentify until you click Connect. When you do, you grant access so the AI can read your store and answer accurately, and you can revoke that access at any time with a single click. Your conversation history lives in your Egentify account rather than scattered across your site, and the plugin stores only the connection details it needs in WordPress. Full details are in the External Services section below.

**Why Egentify**

Egentify is built specifically for WooCommerce. It connects to your store in a couple of clicks, grounds every answer in your real data, and keeps you in control with live takeover. There is no long setup, no rebuilding your help docs, and no handing your customers off to a generic bot that does not know your products.

**Perfect for**

Online stores that want to cut response times, reduce repetitive support work, and give shoppers round-the-clock help without hiring a bigger team. It is especially useful for stores fielding repeated "where is my order?" questions, shops with a small support team, and storefronts that get traffic well outside business hours.

**Start free, scale when you grow.** Egentify's free-forever plan includes the AI chat widget, 50 conversations every month, chat and email support, AI-powered ticketing, and live takeover, so you can add AI support to your store at no cost. Paid plans add more monthly conversations and voice support. Requires an Egentify account. [Sign up at egentify.com](https://egentify.com) to get started.

== External services ==

This plugin connects your store to Egentify, a third-party AI support service operated by Egentify, Inc. Nothing is sent anywhere until you click **Connect to Egentify** in the plugin settings.

**When you connect:** the plugin completes a one-time secure handshake with Egentify (sending your store URL and a connection ID) and creates WooCommerce API keys that it sends to Egentify so the service can read and manage your store on your behalf. This includes your **orders, customers (names, addresses, and email addresses), products, and refunds**. You can revoke access anytime by clicking **Disconnect**, or by deleting the "Egentify (auto-generated)" key under WooCommerce → Settings → Advanced → REST API. Uninstalling the plugin removes it too.

**While connected:** the plugin sends your widget settings when you save them, plus a weekly health check reporting your plugin, WordPress, WooCommerce, and PHP versions. When a customer completes an order after adding products through the chat, the plugin reports the order id, number, status, total, currency, item count, and the chat reference (no customer details) so your Egentify dashboard can show revenue from the chat.

**When a customer chats:** their message, and any order or product details the AI looks up to answer it, are sent to Egentify. Logged-in customers are identified by a secure token so replies can be personalized.

**What Egentify sends back:** the chat widget and the AI's responses.

By connecting, you agree to Egentify's [Terms of Service](https://egentify.com/terms) and [Privacy Policy](https://egentify.com/privacy).

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, or extract into `/wp-content/plugins/egentify-for-woocommerce`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Egentify** in the WordPress admin sidebar.
4. Click **Connect to Egentify** and authorize the connection.
5. Customize widget appearance, welcome message, and starter buttons in the plugin settings.

== Frequently Asked Questions ==

= Do I need an Egentify account? =

Yes. This plugin is a client for the Egentify service. Sign up at [egentify.com](https://egentify.com) first. The plugin's Connect button completes the link from your store side.

= Is Egentify really free? =

Yes. Egentify's free-forever plan includes 50 conversations every month at no cost, with no time limit, along with the AI chat widget, email support, AI-powered ticketing, and live takeover. If your store grows past that, paid plans add higher conversation volume and voice support. The WordPress plugin itself is, and always will be, free.

= Can I take over a conversation from the AI? =

Yes. You can watch conversations live in your Egentify dashboard and jump in at any time to reply to a customer yourself, so you stay in control of every conversation.

= What happens when the AI can't answer a question? =

When a question is beyond what the AI should handle on its own, it escalates the conversation to your team. You are notified, and you can take over the chat in real time from your Egentify dashboard or follow up later as a support ticket, so customers are never left at a dead end.

= Do I need to set up a knowledge base or train the AI? =

No. Egentify answers from your real WooCommerce catalog, store pages, and policies out of the box, so there is nothing to train and no knowledge base to build before you go live. When you want more control, you can add a supplemental knowledge base in Egentify: custom answers for things only your AI agent should know, such as a current sale, active coupon codes, or special policies. You can also give the assistant a name and instructions to shape its tone and how it handles topics like refunds.

= Will it slow down my store? =

No. The chat widget loads a lightweight script from Egentify's servers, and the AI itself runs on Egentify's side, not on your hosting, so your storefront stays fast.

= Does it work with my theme, and where does the widget appear? =

Yes. The widget works on any standard WooCommerce storefront theme. By default it auto-injects into the footer on every page once you connect. To show it only on specific pages instead, turn off **Auto Inject Widget** and add the `[egentify_chat_widget]` shortcode wherever you want it.

= Can I customize the widget's appearance? =

Yes. Position (bottom-right / bottom-left), side and bottom offsets, chat window corner radius, primary color, welcome message, and up to four starter buttons are all configurable from the Egentify admin page.

= Where do I see the chat conversations? =

In your Egentify dashboard at [egentify.com](https://egentify.com), not in WordPress. The plugin only adds the widget and the API endpoints Egentify uses.

= How do I disconnect? =

Click **Disconnect** in the connected state of the Egentify admin page. This removes the stored signing secret and API keys. The widget stops loading.

= Is my customers' data safe? =

Egentify only receives the data it needs to answer a customer and manage your store, and the plugin sends nothing until you connect. Requests between your store and Egentify are signed, you can revoke access at any time with one click, and conversation data lives in your Egentify account rather than on your site. See the External Services section above for the full breakdown of what is shared and when.

= What data is stored locally in WordPress? =

Just the connection details: your Egentify project ID, the security keys used to connect, and your widget settings. Orders placed with help from the chat also carry a hidden chat reference in their order metadata. When the chat adds a product to the cart, it sets a small cookie in the customer's browser for up to 30 days so the order can be linked to the conversation. Chat conversations are not stored in WordPress. They live in your Egentify account.

== Changelog ==

= 1.0.4 =
* Initial release.

== Upgrade Notice ==

= 1.0.4 =
Initial release.
