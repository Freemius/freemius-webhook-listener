# Freemius WebHook Listener

A simple WebHook listener for subscribing Freemium users to 3rd party services like MailChimp.


# Setup

1. Install and activate the plugin on the site that will receive remote calls from Freemius events

2. In your Freemius dashboard under *Settings > WebHooks* set the site URL where the listener was installed and specify a service to pass to the listener using `?fwebhook=<service_name>` (ie: for 'mailchimp' you would set http://your-site.com?fwebhook=mailchimp)
