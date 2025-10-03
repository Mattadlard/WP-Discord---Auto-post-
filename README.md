# WP-Discord---Auto-post-
Post blog articles to your Discord Channel. 

Step-by-Step Guide:
1. Open Discord (desktop app or web)
2. Go to your server where you want posts to appear
3. Open Server Settings

Click on the server name at the top left
Select "Server Settings" from the dropdown

4. Navigate to Integrations

In the left sidebar, click "Integrations"

5. Create a Webhook

Click "Webhooks" (or "Create Webhook" if it's your first one)
Click "New Webhook" button

6. Configure the Webhook

Give it a name (like "WordPress Bot")
Important: Select the channel where you want posts to appear
Optionally upload an avatar image for the bot

7. Copy the Webhook URL

Click "Copy Webhook URL"
It will look something like: https://discord.com/api/webhooks/123456789/AbCdEfGhIjKlMnOpQrStUvWxYz

8. Paste into WordPress

Go to WordPress Admin → Settings → Discord Poster
Paste the webhook URL into the "Discord Webhook URL" field
Configure your other settings
Click "Send Test Message" to verify it works!

Quick Troubleshooting:

Can't find Integrations? You need "Manage Webhooks" permission on the server
Test message not appearing? Double-check you selected the right channel
Getting errors? Make sure you copied the entire URL (they're long!)

That's it! Once the webhook is set up, your WordPress posts will automatically appear in that Discord channel.
