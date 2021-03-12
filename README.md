# Automatic harvest report notifications 
`v1.0.0`

__FEATURED TODO__

+ function by customize message
+ Make support others notify channels. Slack, mail, etc. 

__Installations__

1. Run `composer install`
2. Run `cp .env.example .env`
3. Make Personal Access Token `https://id.getharvest.com/oauth2/access_tokens/new`
4. Make telegram bot & added public channel

Example using: `php HarvestReportNotify.php -f"2020-06-25" -t"2020-06-25"`
