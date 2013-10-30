Installation
============

1. Upload this plugin to your plugins directory, e.g. wp-content/plugins/
2. Activate the plugin within the WordPress admin.
3. Enable this payment gateway by selecting it under Group Buying > Payment Settings within the WordPress admin.
4. Configure the gateways settings after saving the payment gateway selection.

## Test Account

[Signup](http://docs.amazonwebservices.com/AmazonFPS/latest/SandboxLanding/index.html)
[AWS](http://aws.amazon.com/)



## Sign Up for an Amazon FPS Developer Account
### First, sign up for your Amazon FPS developer account.

To sign up for an Amazon FPS developer account
1. Go to http://payments.amazon.com.
2. Click on the Developers tab.
3. Click on the Sign Up For Amazon FPS button.
4. Follow the instructions on the subsequent pages to set up a developer account.


### Amazon Payments Business Account

An Amazon Payments business account is a requirement for using some Amazon FPS products.You
create a business account, which enables you to receive payments from Amazon, as the part of creating
a developer account.

Sign Up for an Amazon Web Services Account
You sign up for an Amazon Web Services (AWS) account as the part of creating an Amazon FPS developer
account. The AWS account allows you to make API calls to the Amazon FPS Sandbox and production
environment.

You can also sign up for an AWS account at the AWS website at http://aws.amazon.com.

The following security credentials are automatically associated with your AWS account:

* AWS Access Key ID—You use this to identify yourself when you send requests to the Co-Branded
service or when you send REST requests to Amazon FPS.
* AWS Secret Access Key—You use this to generate URL signatures that provide tamper-proof requests.
Important

You can use these security credentials in both the Sandbox and the production environment.

To view your Access Key ID and Secret Access Key
1. Go to the Amazon Security Credentials page at http://aws.amazon.com/security-credentials. If you
are not logged in, you will be prompted for your user name and password.
2. Your Access Key ID is displayed on the Security Credentials page in the Access Credentials area.
Your Secret Access Key remains hidden as a further precaution as shown in the following figure.
3. To display your Secret Access Key, on the Access Keys tab, under Secret Access Key, click Show.

The AWS Security Credentials page also enables you to manage a second set of credentials for rotation.
This feature enhances the security of your account.
For more information, see Access Key Rotation in the Amazon FPS Basic Quick Start Developer Guide.

### Error: "Sandbox only error: Caller does not have a FPS Caller Account"

Open a support forum thread so that an Amazon AWS support engineer can properly flag your account to accept Payment API calls.
[Forum Post](https://forums.aws.amazon.com/message.jspa?messageID=493083#493635)
