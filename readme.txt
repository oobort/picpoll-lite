=== PicPoll Photo Voting Game ===
Contributors: leadmuffin
Tags: voting, polls, photos, images, engagement, interactive, regions, categories, game, gameify
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.8.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create engaging photo voting games with regional voting breakdowns, custom categories, and beautiful styling options.

== Description ==

**PicPoll** transforms your WordPress site into an interactive voting experience! Let your visitors vote on photos with beautiful, customizable voting interfaces that show real-time results broken down by region.

Perfect for:
* **Food blogs** - "Which dish looks most appetizing?"
* **Travel sites** - "Which destination would you visit first?"
* **Fashion blogs** - "Which outfit do you prefer?"
* **Photography portfolios** - "Which photo captures the best moment?"
* **Product comparisons** - "Which design appeals to you more?"
* **Entertainment sites** - "Which movie poster is more exciting?"

### 🎯 Key Features

**✨ Beautiful Voting Interface**
* Clean, modern design that works on any theme
* Customizable colors, fonts, and styling
* Mobile-responsive voting cards
* Smooth animations and transitions

**📊 Regional Voting Breakdown**
* See how different regions vote on each item
* Built-in support for US, Canada, UK, and Japan
* Prompt users to select their region at start
* Compare voting patterns across locations

**🏷️ Category System**
* Organize items with custom categories
* Filter votes by specific categories
* Create themed voting sessions
* Easy category management

**⚙️ Flexible Shortcode System**
* `[vote_game]` - Simple integration anywhere
* Customizable parameters for each instance
* Built-in shortcode builder in admin
* Live preview functionality

**🎨 Styling Options**
* Custom accent colors
* Adjustable card borders and radius
* Font family customization
* Responsive bar charts for results

### 💡 How It Works

1. **Add Items**: Upload photos with titles and optional descriptions
2. **Set Categories**: Organize your items with custom categories  
3. **Configure Options**: Set your vote button labels and styling
4. **Insert Shortcode**: Add `[vote_game]` to any post or page
5. **Engage**: Visitors vote and see real-time results by region

### 🚀 Shortcode Examples

**Basic Usage:**
`[vote_game]`

**Customized Voting Game:**
`[vote_game limit="20" color="#FF6B6B" category="food,drinks" regions="US:United States|CA:Canada"]`

**Parameters:**
* `limit` - Number of items to show (default: 30)
* `random` - Randomize order (1 for yes, 0 for no)
* `color` - Accent color (hex code)
* `category` - Filter by category slugs
* `regions` - Custom region definitions
* `show_excerpt` - Show item descriptions (1 or 0)

### 🔧 Admin Features

**Easy Item Management**
* Add items with drag-and-drop image upload
* Bulk category assignment
* Quick edit and delete options
* Visual item overview

**Shortcode Builder**
* Visual shortcode configuration
* Live preview functionality
* Copy-to-clipboard convenience
* Parameter validation

**Styling Controls**
* Color picker for accent colors
* Slider controls for dimensions
* Font family selection
* Real-time preview

### 🌟 Pro Features Available

Upgrade to **PicPoll Pro** for advanced functionality:

* **Advanced Behavior Settings** - Login requirements, IP limits, minimum sample sizes
* **Bulk CSV Upload** - Import hundreds of items at once
* **Results & Adjustments** - Detailed analytics and vote adjustments
* **Custom Region Management** - Unlimited regions with automatic geotracking
* **Cloudflare Integration** - Automatic visitor region detection
* **Priority Support** - Direct email support from the developer

[**Upgrade to PicPoll Pro →**](https://leadmuffin.com/plugins/picpoll)

### 🛡️ Security & Performance

* **Secure by Design** - Follows WordPress security best practices
* **Optimized Queries** - Efficient database operations
* **Escape All Output** - XSS protection throughout
* **Nonce Verification** - CSRF protection on all forms
* **Sanitized Inputs** - All user data properly validated

### 🎯 Perfect For

* **Content Creators** - Boost engagement with interactive content
* **Bloggers** - Create viral voting content
* **Businesses** - Gather customer preferences
* **Educators** - Interactive classroom polls
* **Communities** - Fun group voting activities

== Installation ==

### Automatic Installation

1. Log in to your WordPress admin dashboard
2. Navigate to **Plugins** → **Add New**
3. Search for "PicPoll"
4. Click **Install Now** and then **Activate**

### Manual Installation

1. Download the plugin ZIP file
2. Go to **Plugins** → **Add New** → **Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. Activate the plugin

### Setup

1. Go to **PicPoll** → **Items** to add your first voting items
2. Visit **PicPoll** → **Styling** to customize appearance
3. Use **PicPoll** → **Shortcode Builder** to create your first voting game
4. Add the generated shortcode to any post or page

== Frequently Asked Questions ==

= How do I add voting items? =

Go to **PicPoll** → **Items** in your WordPress admin. Click "Add New Item," upload an image, add a title and optional description, assign categories, and save.

= Can visitors vote multiple times? =

By default, each IP address can only vote once per item to prevent spam. This helps ensure fair voting results.

= How do I customize the voting options? =

Visit **PicPoll** → **Vote Options** to customize what the voting buttons say. You can change from the default "Vote Option 1" to anything like "Love it!" and "Not for me."

= Can I filter votes by category? =

Yes! Use the `category` parameter in your shortcode: `[vote_game category="food,travel"]` to show only items from those categories.

= How do regions work? =

PicPoll prompts users to select their region when they start voting. Results are then broken down by region so you can see how different areas vote on each item.

= Can I customize the colors and styling? =

Absolutely! Go to **PicPoll** → **Styling** to customize colors, fonts, borders, and more. You can also use the `color` parameter in shortcodes for per-instance customization.

= Is there a limit to how many items I can add? =

The free version has no hard limits, but for optimal performance, we recommend keeping it under a few hundred items. PicPoll Pro offers bulk upload for larger collections.

= Can I export the voting results? =

Voting results are stored in your WordPress database. PicPoll Pro includes advanced analytics and export functionality.

= Does it work with my theme? =

Yes! PicPoll is designed to work with any properly coded WordPress theme. The styling is self-contained and responsive.

= Can I use it on multiple sites? =

The free version can be used on unlimited sites. PicPoll Pro licenses are per-site.

== Screenshots ==

1. **Voting Front-end Interface** - Clean, modern voting cards
2. **Results Front-end Interface** - Clean, modern poll results card
3. **Settings Admin Interface** - Easy-to-use interface with options
4. **Vote Options Admin Interface** - Easy-to-use interface to add voting options
5. **Add Item Admin Interface** - Easy-to-use interface to add new items


== Changelog ==

= 1.8.13 =
*Release Date: January 15, 2025*

**Security & Performance**
* Complete security audit and hardening
* Improved database query optimization
* Enhanced input validation and output escaping
* Better error handling and user feedback

**Features**
* Streamlined admin interface
* Improved shortcode builder with live preview
* Better category management
* Enhanced mobile responsiveness

**Bug Fixes**
* Fixed various edge cases in voting logic
* Improved image upload handling
* Better nonce verification throughout
* Resolved styling conflicts with some themes

= 1.8.12 =
* Initial public release
* Core voting functionality
* Basic admin interface
* Shortcode support

== Upgrade Notice ==

= 1.8.13 =
This version includes important security improvements and enhanced features. Please update as soon as possible.

== Support ==

**Need Help?**

* **Documentation**: [Plugin Documentation](https://leadmuffin.com/plugins/picpoll/docs)
* **Support**: Email hi@leadmuffin.com for assistance
* **Pro Upgrade**: [Get PicPoll Pro](https://leadmuffin.com/plugins/picpoll) for advanced features

**Developer Notes**

This is my first WordPress plugin and I'm actively developing and improving it based on user feedback. If you encounter any issues or have feature suggestions, please don't hesitate to reach out!

**Love PicPoll?**

* Leave a ⭐⭐⭐⭐⭐ review to help others discover it
* [Upgrade to Pro](https://leadmuffin.com/plugins/picpoll) to support development
* Share your voting games on social media

== Privacy Policy ==

PicPoll is designed with privacy in mind:

* **Data Collection**: Only stores votes (choice + IP + optional region)
* **No Personal Data**: No emails, names, or personal information required
* **IP Addresses**: Used only to prevent duplicate voting, not tracked
* **Regions**: Optional and user-selected, not automatically detected
* **Third Parties**: No data shared with external services
* **Cookies**: Does not set any tracking cookies

For PicPoll Pro features like automatic region detection, please see the Pro privacy policy.

== Technical Requirements ==

* **WordPress**: 5.0 or higher
* **PHP**: 7.4 or higher (8.0+ recommended)
* **MySQL**: 5.6 or higher
* **Server**: Any standard WordPress hosting
* **Browser**: Modern browsers (Chrome, Firefox, Safari, Edge)

== Credits ==

* **Developer**: LeadMuffin
* **Website**: [leadmuffin.com](https://leadmuffin.com/plugins)
* **Plugin URI**: [PicPoll Plugin Page](https://leadmuffin.com/plugins/picpoll)

Built with ❤️ for the WordPress community.
