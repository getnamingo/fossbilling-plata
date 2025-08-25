# plata by Mono for FOSSBilling
[plata by Mono](https://monobank.ua/plata-by-mono) payment gateway module for [FOSSBilling](https://fossbilling.org)

## Installation

### Automated installation (TODO)
```bash
git clone https://github.com/getnamingo/fossbilling-plata
mv fossbilling-plata/PlataMono /var/www/library/Payment/Adapter/
chown -R www-data:www-data /var/www/library/Payment/Adapter/PlataMono
```

Now, continue with steps 4-5 from the manual installation section.

### Manual installation
1. Download the latest release from [GitHub](https://github.com/getnamingo/fossbilling-plata).
2. In your FOSSBilling installation, navigate to `/library/Payment/Adapter` and create a new folder named `PlataMono`.
3. Extract the contents of the downloaded `PlataMono` folder into the newly created `PlataMono` folder.
4. In the FOSSBilling admin panel, go to the "Payment gateways" page, which is located under the "System" menu.
5. Find PlataMono under the "New payment gateway" tab and click the gear (cog) icon to install and configure it.

## Licensing
This extension is licensed under the Apache 2.0 license. See the [LICENSE](LICENSE) file for more information.

## Disclaimer
This extension is not affiliated with monobank in any way.