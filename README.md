# GotoWebinar Plugin for Mautic5 based on MauticCitrixBundle

Webmecanik forked and rewritten the Go To Webinar integration from Mautic 4 to Mautic 5. You can continue to use it without any issues. If you were already using the Citrix plugin, there is no need to reauthorize it. The plugin makes use of the configuration keys provided by MauticCitrixBundle for simplicity. 

**Note**: It only implements the GotoWebinar integration from Mautic 5, GotoMeeting or GotoTraining are not supported anymore in Mautic 5

## Installation

To ensure proper functionality, please merge the following pull request: https://github.com/mautic/mautic/pull/12760 before using this plugin. 

To install the plugin, follow these steps:
1. Unpack the plugin into the plugins/MauticCitrixBundle folder.
2. Run the command `php app/console clear:cache`
3. Run the command `php app/console mautic:plugins:reload` to register the plugin

## To set up GoToWebinar, follow these steps:

1. Visit the following link to access the Legacy documentation specifically for GoToWebinar integration: [https://github.com/mautic/mautic-documentation/blob/main/pages/15.Plugins/03.Citrix/docs.en.md](https://github.com/mautic/mautic-documentation/blob/main/pages/15.Plugins/03.Citrix/docs.en.md)

2. Use the information provided in the documentation to configure your GoToWebinar setup.

Note: Ignore any instructions related to GoToMeeting or GoToTraining, as they are not relevant for this particular setup.

That's it! You'll now be able to integrate GoToWebinar successfully using the Legacy documentation.
