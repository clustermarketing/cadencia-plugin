=== Cadência ===
Contributors: soucluster
Tags: seo, json-ld, schema, rank math, yoast
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress blog to CadêncIA, the AI content platform that plans, writes and publishes SEO-ready articles for your business.

== Description ==

**Your blog working for you, every single day.**

Publishing content brings results, but it demands what your team never has to spare: time, consistency and SEO expertise. **CadêncIA** (https://cadencia.soucluster.com.br) solves all three at once. It is the content platform by Cluster that plans, writes and publishes SEO-optimized articles straight to your WordPress site, at the pace search engines reward: never skipping a week.

This plugin is the official bridge between your WordPress site and the CadêncIA platform.

= How it works =

1. **Strategy first.** Your brand, personas and keyword map become a continuous flow of article briefs aligned with your business, with automatic keyword cannibalization checks so your pages never compete with each other.
2. **Complete articles, not drafts.** Every article ships with optimized title and meta description, focus keyword, internal links, an FAQ section and Schema.org structured data that Google and AI assistants understand. Cover and body images are AI-generated to match your brand.
3. **You approve, CadêncIA publishes.** A simple portal to review and approve, with WhatsApp reminders when an article is waiting. Scheduling and cadence are automatic.
4. **Results measured, not promised.** A panel with Google Search Console built in: clicks, impressions, ranked keywords and month-over-month growth, plus an SEO audit that finds and prioritizes site issues.

= Found by AI, not just by Google =

The **GEO module** monitors how ChatGPT, Gemini, Perplexity and Claude mention your brand when people ask about your market. See where you show up, where you are missing, and measure the before and after of every action. Your content starts working to be found in AI answers too, which is where search is heading.

**Who it is for:** businesses that know they need a blog but have no content team, and agencies that want to scale production without losing quality. No team to hire, no complicated tooling. You approve from your phone; CadêncIA does the rest.

= What this plugin does on your site =

* Enables writing Rank Math and Yoast SEO fields through the REST API.
* Renders in the page head the safe JSON-LD structured data sent by CadêncIA (Article, FAQPage, BreadcrumbList), keeping raw JSON out of the post body.
* Optional "Summarize this article with AI" widget on posts.
* Optional audio narration player for articles.
* Site ownership verification endpoint.
* Applies 301 redirects from pages merged by CadêncIA to the consolidated article.

The plugin does not collect data and does not call external services on its own; it only exposes REST API fields to the CadêncIA account authenticated with an Application Password. Without a CadêncIA subscription the plugin stays idle and adds nothing to your pages.

== Frequently Asked Questions ==

= Do I need a CadêncIA account? =

Yes. The plugin is the connection between your WordPress site and the CadêncIA platform. Visit https://cadencia.soucluster.com.br to learn more.

= Does it work with Rank Math and Yoast SEO? =

Yes, both. CadêncIA writes the SEO fields of whichever plugin you use.

= Does it slow down my site? =

No. It only registers REST API fields and prints the structured data already stored with each post. There are no external calls on page load.

= Is my data sent anywhere? =

No. The plugin does not collect or send data by itself. All writing happens through the standard WordPress REST API, authenticated with an Application Password that you control and can revoke at any time.

== Installation ==

1. Upload the zip in Plugins, Add New, Upload Plugin.
2. Activate the plugin.
3. In the CadêncIA panel, click "Verify installation".

== Changelog ==

= 1.7.1 =
* The "Summarize with AI" widget no longer breaks when another plugin injects content before the first paragraph of the post.

= 1.7.0 =
* Plugin folder renamed to match the product name. Internal function names are now prefixed so the new folder can coexist with an older install without breaking the site; the previous copy is deactivated automatically on first load.

= 1.6.0 =
* Added the redirects endpoint: when CadêncIA merges pages that competed for the same search, the old paths now 301 to the consolidated article.

= 1.5.4 =
* Redesigned the AI summary widget with isolated, responsive styles and local service icons.
* Added the Google Preferred Sources shortcut using the site's origin without external requests on page load.

= 1.5.3 =
* Directory compliance: English readme, current "Tested up to", plugin name without restricted terms.

= 1.5.2 =
* GPL license header, readme.txt and escaping/text domain adjustments.

= 1.5.1 =
* Renamed to the CadêncIA brand.

= 1.5.0 =
* Safe JSON-LD via meta, AI summary and audio narration.
