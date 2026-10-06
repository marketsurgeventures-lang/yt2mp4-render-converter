> Current deployment status (6 October 2026): the existing Free backend and WordPress integration are configured, but live YouTube source extraction is blocked by verification. Public conversion remains disabled. See DEPLOYMENT-STATUS.md before following new-installation steps; do not create a duplicate service or reset the existing shared secret.

# Pehle yeh parhein

Code aur WordPress plugin tayyar hain. **83 local checks pass hain; abhi Render par live deployment nahi hui.** Asal YouTube access aur aapki WordPress website par final test deployment ke baad hoga.

## Aapko kya chahiye

- Aapki WordPress website ka asal HTTPS URL.
- Render account aur GitHub account.
- Ek private random shared secret jo Render aur WordPress settings dono mein same hoga.

## Setup ka seedha tareeqa

1. Full ZIP extract karein. `render-video-converter` folder ke andar ka code GitHub repository ke root par upload hoga; Dockerfile root par hona chahiye.
2. Render par is repo se **Docker Web Service** banayein. Instance **Free**, health check `/healthz`, Dockerfile `./Dockerfile`, aur auto deploy Off rakhein. Shuru mein `MSVCR_ENABLED=0` rehne dein.
3. Service live hone par uska asal `https://...onrender.com` URL milega. Render Environment mein apni `WORDPRESS_ORIGIN`, private `MSVCR_SHARED_SECRET` aur `MSVCR_ENABLED=1` save karke deploy karein. Exact values aur secret banane ka tareeqa `RENDER-SETUP.md` mein hai.
4. WordPress mein **render-wordpress-plugin.zip** upload karke activate karein. Settings → Render Video Converter mein Render URL aur wahi secret dalein, Enable karke save karein.
5. Render URL ke aakhir mein `/healthz` kholein. Ready hone par WordPress se **Check Render connection** karein.
6. Apne page ke Shortcode block mein `[render_video_converter]` dalein aur publish karein.

Default pilot: 20 conversion requests rozana, 2-minute video, 360p tak MP4, 20 MiB final file. MP3 bhi available hai. Failed request bhi allowance use karti hai. Yeh limits monthly free bandwidth ki guarantee nahi hain.

Hostinger par koi FFmpeg ya VPS install nahi karna. Conversion Render par hogi. Free hosting ki monthly limits aur interruption behavior `RENDER-SETUP.md` mein parhein. 100–200 conversions rozana free aur reliable chalne ka wada is package mein nahi kiya gaya.

Secret ko GitHub, public page ya chat mein share na karein. Manual steps samajhna mushkil ho to deploy karne wale ko full package aur apni website ka URL dein; account access provider ke secure login flow se dein.
