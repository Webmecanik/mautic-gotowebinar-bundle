### Mautic GotoWebinar Plugin based on MauticCitrixBundle

### Installation
This plugin requires https://github.com/mautic/mautic/pull/12760 to be merged in order to function properly. 

You need to unpack this plugin into plugins/MauticCitrixBundle folder and run `php app/console mautic:plugins:reload` to register it.

You do not need to reauthorize the plugin if you already have Citrix plugin installed.

### Notes 

Plugin reuses configuration keys provided by MauticCitrixBundle for simplicity
Plugin implements only GotoWebinar API, not GotoMeeting or GotoTraining



