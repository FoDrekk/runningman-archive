-- ============================================================
-- Fix Guest Data — Proper Korean Name Splitting
-- Fixes bug: 'Ahn Jae- hyun Kang So-ra' (2 people merged)
--        → 'Ahn Jae-hyun' + 'Kang So-ra' (correctly split)
-- Source: Wikipedia episode lists (all years 2010-2026)
-- Coverage: 476 episodes, 880 unique guests
-- ============================================================

SET NAMES utf8mb4;

-- Step 1: Wipe existing guest links (old data has merged-name bug)
DELETE FROM episode_guests;
DELETE FROM guests;

-- Step 2: Insert all unique guest names
INSERT INTO guests (name_romanized) VALUES
  ('Ahn'),
  ('Ahn Bo-hyun'),
  ('Ahn Eun-jin'),
  ('Ahn Gil-kang'),
  ('Ahn Hye-jin'),
  ('Ahn Hyo-seop'),
  ('Ahn Jae-hyun'),
  ('Ahn Ji-young BewhY Hyojung'),
  ('Ahn Mun-sook'),
  ('Ahn Sung-ki'),
  ('Ahyeon Asa'),
  ('Aiki Honey J LEEJUNG MONIKA'),
  ('Ailee Joon'),
  ('Ailee Lim Seul-ong'),
  ('Alex'),
  ('An Yu-jin Gaeul'),
  ('An Yu-jin Rei'),
  ('Andy Bobby'),
  ('Andy Dong-wan Eric'),
  ('Andy Eric'),
  ('Apink'),
  ('Arin Jin Ji-hee San'),
  ('B.I'),
  ('B.I Chae-yeon'),
  ('BTS'),
  ('Bada Jo Jung-chi'),
  ('Bae Hye-ji Jonathan Yiombi'),
  ('Bae Seong-woo'),
  ('Bae Yoon-kyung'),
  ('Baek Ji-young'),
  ('Baek Jin-hee Chansung Jun. K Junho Nichkhun Taecyeon Wooyoung'),
  ('Baek Sung-hyun'),
  ('Bang'),
  ('Bang Hyo-rin Byun Yo-han'),
  ('Baro'),
  ('Beomgyu Huening Kai Soobin Taehyun Yeonjun'),
  ('Bibi Jeong Jun-ha Luda'),
  ('Bo-ra'),
  ('Bo-ra Chansung'),
  ('Bo-ra Da-som Hyo-rin So-you Shownu'),
  ('Bo-ra So-you'),
  ('BoA'),
  ('Bobby'),
  ('Bona'),
  ('Byeon Woo-seok'),
  ('Byul'),
  ('Byul Lee Si-young'),
  ('Byung-eun'),
  ('Cha Chung-hwa'),
  ('Cha Eun-woo Moonbin'),
  ('Cha In-pyo Ricky'),
  ('Cha Jun-hwan'),
  ('Cha Seung-won'),
  ('Cha Tae-hyun'),
  ('Cha Tae-hyun Huening Kai Yeonjun'),
  ('Cha Ye-ryun'),
  ('Cha Yu-ram Fabien'),
  ('Chae Jong-hyeop'),
  ('Chae Won-bin'),
  ('Changmin U-Know Yunho'),
  ('Chanmi Hyejeong Jimin Seolhyun Yuna'),
  ('Chansung Jun. K Junho Nichkhun Wooyoung'),
  ('Chansung Taecyeon'),
  ('Chansung Wooyoung'),
  ('Cheon Sung-moon Jeon'),
  ('Cho Jin-woong'),
  ('Cho Jun-ho'),
  ('Cho Jun-hyun Hyoyeon Yuri'),
  ('Cho Yi-hyun'),
  ('Choa'),
  ('Choa Jo Se-ho'),
  ('Choi Bu-kyung'),
  ('Choi Daniel'),
  ('Choi Doo-ho'),
  ('Choi Gwi-hwa'),
  ('Choi Hee'),
  ('Choi Ji-woo'),
  ('Choi Jin-hyuk'),
  ('Choi Kang-hee'),
  ('Choi Min-ho'),
  ('Choi Min-ho Hoya'),
  ('Choi Min-ho Hyo-rin Siwon Sohee'),
  ('Choi Min-jeong'),
  ('Choi Min-soo'),
  ('Choi Min-yong'),
  ('Choi Si-won'),
  ('Choi Tae-joon'),
  ('Choi Yeo-jin'),
  ('Choi Yoo-jung Chungha Mijoo'),
  ('Choi Yu-hwa'),
  ('Choiza'),
  ('Choo Shin-soo'),
  ('Choo Sung-hoon'),
  ('Chun Jung-myung'),
  ('Chun Myung-hoon Danny'),
  ('Chung'),
  ('Chungha Seol In-ah'),
  ('Code Kunst'),
  ('D.O.'),
  ('DEX'),
  ('Da-som Hyo-rin'),
  ('Dae-ho'),
  ('Dae-sung'),
  ('Dae-sung G-Dragon'),
  ('Dae-sung G-Dragon Seung-ri Tae-yang'),
  ('Dahyun Kyuhyun'),
  ('Dayoung Hyejeong Seolhyun'),
  ('Defconn Kim Bo-sung'),
  ('Do Sang-woo'),
  ('Do Sang-woo Hae-ryung'),
  ('Do-hee'),
  ('Donghae Eun-hyuk Leeteuk Yesung Irene'),
  ('Dongwoo Dongwoon Eric'),
  ('Donnie Yen'),
  ('Eom Ji-yoon'),
  ('Esom Kim Kyung-'),
  ('Eun Ji-won'),
  ('Eun Ji-won Jay'),
  ('Eun Ji-won Jessica'),
  ('Eun Ji-won Kai'),
  ('Eun-hyuk Hong Jin-young'),
  ('Eunhyuk Ham Eun-jung'),
  ('Eunhyuk Kyuhyun Leeteuk'),
  ('Eunji Minyoung Yujeong'),
  ('Eunseo Jin Goo'),
  ('Fei Hong Jin-young'),
  ('Fei Kim Sung-ryung'),
  ('Gaeko'),
  ('Gary'),
  ('Gary''s guests'),
  ('Gil'),
  ('Go Ah-sung'),
  ('Go Ara'),
  ('Go Ara Hyo-min'),
  ('Go Bo-gyeol'),
  ('Go Chang-suk'),
  ('Go Joo-won'),
  ('Go Min-si'),
  ('Go Soo'),
  ('Go Young-bae'),
  ('Gong'),
  ('Gong Hyo-jin'),
  ('Gong Hyung-jin'),
  ('Gong Myung'),
  ('Gong Myung Jin Seon-kyu'),
  ('Gong Seung-yeon'),
  ('Goo Ha-ra'),
  ('Goo Hara'),
  ('Ha Do-kwon'),
  ('Ha Jae-sook'),
  ('Ha Ji-won'),
  ('Ha Seok-jin'),
  ('Ha Yeon-joo Kwak Si-yang'),
  ('Ha Yeon-soo'),
  ('Haewon'),
  ('Haha''s guests'),
  ('Han Bo-reum Hani'),
  ('Han Chae-young'),
  ('Han Da-gam'),
  ('Han Eun-jung'),
  ('Han Ga-in'),
  ('Han Groo'),
  ('Han Hye-jin'),
  ('Han Hye-jin Jin Se-yeon Min-ah'),
  ('Han Hye-jin Key'),
  ('Han Hyo-joo'),
  ('Han Jae-suk Sandara'),
  ('Han Ji-eun'),
  ('Han Ji-min'),
  ('Han Sang-jin'),
  ('Han Seung-yeon'),
  ('Han Sun-hwa'),
  ('Han Ye-ri'),
  ('Han Ye-seul'),
  ('Hani'),
  ('Heechul'),
  ('Henry Cavill'),
  ('Henry Kangnam'),
  ('Heo'),
  ('Heo Kyung-hwan'),
  ('Heo Kyung-hwan KCM'),
  ('Heo Kyung-hwan Seogy'),
  ('Heo Sung-tae'),
  ('Heo Young-ji'),
  ('Hong Eun-chae Sakura'),
  ('Hong Hyun-hee'),
  ('Hong Jin-ho'),
  ('Hong Jin-ho Hyun Joo-yup'),
  ('Hong Jin-ho Jeong Jeong-ah'),
  ('Hong Jin-ho Jonathan Yiombi'),
  ('Hong Jin-ho Kazuha'),
  ('Hong Jin-ho Keum Sae-rok'),
  ('Hong Jin-ho Mimi'),
  ('Hong Jin-kyung'),
  ('Hong Jin-young'),
  ('Hong Jin-young Jung-in'),
  ('Hong Jong-hyun'),
  ('Hong Kyung'),
  ('Hong Kyung-min'),
  ('Hong Seok-cheon'),
  ('Hong Soo-hyun'),
  ('Hong Ye-ji'),
  ('Hong Yoon-hwa'),
  ('Hoshi Mingyu'),
  ('Hoshi Seung-kwan'),
  ('Hwang Bo-ra'),
  ('Hwang Bo-ra Ryan Reynolds Melanie Laurent Adria Arjona'),
  ('Hwang Chi-yeul'),
  ('Hwang Hee-chan'),
  ('Hwang Jung-min'),
  ('Hwang Kwang-hee'),
  ('Hwang Seok-jeong'),
  ('Hwang Seung-eon'),
  ('Hwang Suk-jung'),
  ('Hwang Woo-seul-hye'),
  ('Hwang Young-hee'),
  ('Hwasa Young K'),
  ('Hye-sung'),
  ('Hye-sung Jun Jin Min-woo'),
  ('Hyeri'),
  ('Hyo-rin'),
  ('Hyo-yeon'),
  ('Hyo-yeon Seo-hyun Soo-young Sunny'),
  ('Hyo-yeon Soo-young Sunny'),
  ('Hyun Young'),
  ('Hyuna'),
  ('Im Ha-ryong'),
  ('Im Ho'),
  ('Im Se-mi'),
  ('Im Soo-hyang'),
  ('Im Won-hee'),
  ('Irene'),
  ('Irene Joy'),
  ('Jackie Chan Siwon'),
  ('Jae-kyung John'),
  ('Jae-suk''s guests'),
  ('Jang Do-yeon'),
  ('Jang Dong-min'),
  ('Jang Dong-min Kangnam'),
  ('Jang Dong-yoon'),
  ('Jang Dong-yoon Keum Sae-rok'),
  ('Jang Hee-jin'),
  ('Jang Hyuk'),
  ('Jang Jin-hee Rothy Seunghee'),
  ('Jang Ki-ha Jun Hyun-moo'),
  ('Jang Seong-woo'),
  ('Jang Shin-young'),
  ('Jang Su-won'),
  ('Jang Won-young'),
  ('Jang Won-young Leeseo Liz'),
  ('Jang Ye-eun'),
  ('Jang Ye-won'),
  ('Jang Yoon-ju'),
  ('Jennie Jin Ki-joo'),
  ('Jennie Jisoo'),
  ('Jennie Jisoo Lisa Rosé'),
  ('Jeon Hye-bin Jeong Jinwoon'),
  ('Jeon Mi-seon'),
  ('Jeon So-mi Jessi'),
  ('Jeon So-min'),
  ('Jeon So-min Kyung Soo-jin'),
  ('Jeon Somi'),
  ('Jeong Hyeong-don Juvie Train'),
  ('Jeong Jun-ha So Yi-hyun'),
  ('Jeong Yu-mi Jimin'),
  ('Jessi'),
  ('Jessi Lee Jin-hyuk'),
  ('Jessi San E'),
  ('Jessi Wooyoung'),
  ('Jessica'),
  ('Ji Chang-wook'),
  ('Ji Jin-hee'),
  ('Ji Seung-hyun'),
  ('Ji So-yun Jong Tae-se'),
  ('Ji Sung'),
  ('Ji Sung Ju Ji-hoon Sam Okyere Son Na-eun'),
  ('Ji Ye-eun'),
  ('Ji Yi-soo'),
  ('Ji-yeon'),
  ('Jin'),
  ('Jin Ji-hee'),
  ('Jin Se-yeon'),
  ('Jin Seo-yeon'),
  ('Jinu Sean'),
  ('Jisoo Jennie Rosé Lisa'),
  ('Jo Bo-ah'),
  ('Jo Byung-gyu'),
  ('Jo Hye-ryun'),
  ('Jo Jin-woong'),
  ('Jo Jung-chi'),
  ('Jo Jung-chi John'),
  ('Jo Jung-suk'),
  ('Jo Jung-suk Yoona'),
  ('Jo Min-su'),
  ('Jo Se-ho'),
  ('Jo Se-ho Kei'),
  ('Jo Se-ho Kyuhyun'),
  ('Jo Yoon-hee'),
  ('John'),
  ('Jonathan Yiombi'),
  ('Jong-hoon'),
  ('Jong-kook''s guests'),
  ('Joo Hyun-young'),
  ('Joo Jong-hyuk'),
  ('Joo Sang-wook'),
  ('Joo Won'),
  ('Joo Woo-jae'),
  ('JooE Kang Seung-yoon'),
  ('Jooheon Kwon Eun-bi'),
  ('Joohoney'),
  ('Joy Jung Kyung-ho'),
  ('Jun Hyo-seong'),
  ('Jun Hyo-seong Mingyu'),
  ('Jun Hyun-moo'),
  ('Jung'),
  ('Jung Chan-sung'),
  ('Jung Doo-hong'),
  ('Jung Eun-ji'),
  ('Jung Eun-ji Son Na-eun'),
  ('Jung Gyu-woon Wang Ji-hye'),
  ('Jung Hee-chul Hyung-sik'),
  ('Jung Hye-in'),
  ('Jung Hye-sung'),
  ('Jung Il-woo'),
  ('Jung Jin-'),
  ('Jung Man-sik'),
  ('Jung Sang-hoon'),
  ('Jung Seung-hwan'),
  ('Jung So-min'),
  ('Jung Won-gwan'),
  ('Jung Woo-sung'),
  ('Jung Woong-in'),
  ('Jung Yong-hwa'),
  ('Junho'),
  ('KCM'),
  ('Kai Kim Ah-young'),
  ('Kang'),
  ('Kang Daniel'),
  ('Kang Ha-neul'),
  ('Kang Han-'),
  ('Kang Han-na'),
  ('Kang Han-na Keum Sae-rok'),
  ('Kang Hoon'),
  ('Kang Hoon Ma Sun-ho'),
  ('Kang Hye-jung'),
  ('Kang Hyeon-soo'),
  ('Kang Jae-jun'),
  ('Kang Ji-young'),
  ('Kang Jung-ho'),
  ('Kang Kyun-sung'),
  ('Kang Mi-na'),
  ('Kang Min-hyuk'),
  ('Kang Min-kyung'),
  ('Kang Seung-hyun'),
  ('Kang So-ra'),
  ('Kang Sung-hoon'),
  ('Kang Sung-jin'),
  ('Kang Susie'),
  ('Kang Tae-oh'),
  ('Kang Tae-oh YOYOMI'),
  ('Kang Tae-oh Yoyomi'),
  ('Kang Ye-won'),
  ('Keum Sae-rok'),
  ('Kim'),
  ('Kim Ah-young'),
  ('Kim Bum-soo'),
  ('Kim Byeong-ok'),
  ('Kim Byung-chul Miyeon'),
  ('Kim Byung-man'),
  ('Kim Chae-won Sakura'),
  ('Kim Dae-myung'),
  ('Kim Do-kyun'),
  ('Kim Do-yeon'),
  ('Kim Dong-'),
  ('Kim Dong-hwi Yoo'),
  ('Kim Dong-hyun'),
  ('Kim Dong-hyun Sung Si-kyung'),
  ('Kim Dong-jun'),
  ('Kim Dong-jun Kwang-hee Tae-heon'),
  ('Kim Dong-jun Park'),
  ('Kim Ga-yeon'),
  ('Kim Gun-mo Koo Jun-yup'),
  ('Kim Ha-neul'),
  ('Kim Ha-yun'),
  ('Kim Hae-sook'),
  ('Kim Hee-ae'),
  ('Kim Hee-chul'),
  ('Kim Hee-chul Leeteuk'),
  ('Kim Hee-jin'),
  ('Kim Hee-jung'),
  ('Kim Hee-sun'),
  ('Kim Hee-won'),
  ('Kim Hwan'),
  ('Kim Hye-ja'),
  ('Kim Hye-jun Sung Dong-il'),
  ('Kim Hye-yoon Lomon'),
  ('Kim Hye-yoon Mingyu Seungkwan'),
  ('Kim Hyun-joong'),
  ('Kim In-kwon'),
  ('Kim Ja-in'),
  ('Kim Jae-duc'),
  ('Kim Jae-hwa'),
  ('Kim Jae-kyung'),
  ('Kim Jae-young'),
  ('Kim Je-dong'),
  ('Kim Jeong-hwan'),
  ('Kim Ji-eun'),
  ('Kim Ji-hoon'),
  ('Kim Ji-min'),
  ('Kim Ji-seok'),
  ('Kim Ji-soo'),
  ('Kim Ji-won'),
  ('Kim Ji-yeon Yook Sung-jae'),
  ('Kim Ji-young'),
  ('Kim Jo-han'),
  ('Kim Jong-myung'),
  ('Kim Joo-hyuk'),
  ('Kim Jun-ho'),
  ('Kim Jun-hyun'),
  ('Kim Jun-hyun Uee'),
  ('Kim Jung-nam'),
  ('Kim Jung-nan'),
  ('Kim Kang-woo'),
  ('Kim Ki-tae'),
  ('Kim Kwang-hyun'),
  ('Kim Kwang-kyu'),
  ('Kim Kyung-ho'),
  ('Kim Min-jae'),
  ('Kim Min-jong'),
  ('Kim Min-ju Roh Yoon-seo'),
  ('Kim Min-jung'),
  ('Kim Min-kyo'),
  ('Kim Min-kyu'),
  ('Kim Min-seo'),
  ('Kim Min-seok'),
  ('Kim Mu-jun'),
  ('Kim Na-hee'),
  ('Kim Nam-joo'),
  ('Kim Rae-won Park'),
  ('Kim Roi-ha Kwak Si-yang'),
  ('Kim Sang-ho Kwak Do-won'),
  ('Kim Sang-joong'),
  ('Kim Sang-kyung'),
  ('Kim Se-jeong'),
  ('Kim Se-jeong Mijoo'),
  ('Kim Seo-hyung'),
  ('Kim Shin-rok'),
  ('Kim Si-eun'),
  ('Kim So-hyun'),
  ('Kim So-hyun Son Jun-ho'),
  ('Kim Soo-hyun'),
  ('Kim Soo-mi'),
  ('Kim Soo-ro'),
  ('Kim Soo-yong'),
  ('Kim Soo-young'),
  ('Kim Sook'),
  ('Kim Sun-a'),
  ('Kim Sun-ah'),
  ('Kim Sung-kyun'),
  ('Kim Sung-soo'),
  ('Kim Tae-hwan'),
  ('Kim Tae-hyung'),
  ('Kim Tae-woo'),
  ('Kim Wan-sun'),
  ('Kim Won-hae'),
  ('Kim Won-hee'),
  ('Kim Won-hyo'),
  ('Kim Won-jun Miryo'),
  ('Kim Woo-bin'),
  ('Kim Woo-bin Rain'),
  ('Kim Ye-rim'),
  ('Kim Ye-won Sunmi Sunny'),
  ('Kim Yeon-koung'),
  ('Kim Yeon-woo Kyuhyun Leeteuk Narsha'),
  ('Kim Yong-ji'),
  ('Kim Yong-man'),
  ('Kim Yoo-jung'),
  ('Kim Yoo-ri'),
  ('Kim You-jung'),
  ('Kim Young-min'),
  ('Ko Sung-hee'),
  ('Koo Ja-cheol'),
  ('Ku Hye-sun'),
  ('Kwang-hee'),
  ('Kwang-soo''s guests'),
  ('Kwon Eun-bi'),
  ('Kwon Eun-bi Tsuki'),
  ('Kwon Hae-hyo'),
  ('Kwon Ri-se'),
  ('Kwon Sang-woo'),
  ('Kwon Yul'),
  ('Kyuhyun'),
  ('Kyuhyun Roy Kim'),
  ('Kyung Soo-jin'),
  ('Kyung Soo-jin Stephanie'),
  ('Kyungri'),
  ('L Lee Joon'),
  ('Lee'),
  ('Lee Beom-soo'),
  ('Lee Bo-young'),
  ('Lee Chang-sub Sung Si-kyung'),
  ('Lee Cho-hee'),
  ('Lee Chun-hee'),
  ('Lee Da-hae'),
  ('Lee Da-hee'),
  ('Lee Deok-hwa'),
  ('Lee Do-hyun'),
  ('Lee Do-hyun Ong Seong-woo'),
  ('Lee Do-hyun Sunmi'),
  ('Lee Dong-hwi'),
  ('Lee Dong-wook'),
  ('Lee Elijah'),
  ('Lee Elijah So-you'),
  ('Lee Elijah Solbi Sung Hoon Sunmi'),
  ('Lee Eun-hyung'),
  ('Lee Gi-kwang'),
  ('Lee Gi-kwang Simon Dominic'),
  ('Lee Guk-joo'),
  ('Lee Guk-joo Kyungri'),
  ('Lee Guk-joo Seolhyun'),
  ('Lee Ha-na'),
  ('Lee Ha-neul'),
  ('Lee Hanee'),
  ('Lee Hee-jin YooA'),
  ('Lee Hee-joon Si-wan'),
  ('Lee Hong-gi'),
  ('Lee Hong-ryul'),
  ('Lee Hye-jeong Seolhyun'),
  ('Lee Hyun-woo'),
  ('Lee Hyun-yi'),
  ('Lee Il-hwa'),
  ('Lee Jae-hoon'),
  ('Lee Jai-jin'),
  ('Lee Je-hoon'),
  ('Lee Jeong-min'),
  ('Lee Ji-hyun'),
  ('Lee Jin-wook'),
  ('Lee Jong-hyun'),
  ('Lee Jong-soo Seolhyun'),
  ('Lee Jong-suk'),
  ('Lee Jong-won'),
  ('Lee Joo-bin So Yi-hyun'),
  ('Lee Joo-yeon'),
  ('Lee Joo-young'),
  ('Lee Joon'),
  ('Lee Joon Seung-ho'),
  ('Lee Joon-gi'),
  ('Lee Juck Muzie'),
  ('Lee Jun-young'),
  ('Lee June-seo'),
  ('Lee Jung-shin'),
  ('Lee Ki-woo'),
  ('Lee Ki-woo Nichkhun'),
  ('Lee Kyu-han'),
  ('Lee Kyung-kyu'),
  ('Lee Kyung-shil'),
  ('Lee Mi-joo'),
  ('Lee Mi-joo Soyou'),
  ('Lee Min-hyuk'),
  ('Lee Min-jung'),
  ('Lee Min-ki'),
  ('Lee Na-eun'),
  ('Lee Sang-hwa'),
  ('Lee Sang-joon'),
  ('Lee Sang-won'),
  ('Lee Sang-yeob'),
  ('Lee Sang-yeob Shorry J'),
  ('Lee Sang-yi'),
  ('Lee Sang-yoon'),
  ('Lee Se-hee'),
  ('Lee Se-young'),
  ('Lee Seo-jin'),
  ('Lee Seok-hoon'),
  ('Lee Seung-gi'),
  ('Lee Seung-hyub'),
  ('Lee Si-a Seungri Sunmi'),
  ('Lee Si-young'),
  ('Lee So-yeon'),
  ('Lee So-young'),
  ('Lee Sun-bin'),
  ('Lee Sun-kyun'),
  ('Lee Sung-jae Skull'),
  ('Lee Sung-kyung'),
  ('Lee Tae-gon'),
  ('Lee Tae-hwan'),
  ('Lee Tae-min'),
  ('Lee Tae-wook Pyeon Yoo-il'),
  ('Lee Wan Lizzy Mikey'),
  ('Lee Won-hee'),
  ('Lee Yeon-hee'),
  ('Lee Yeon-hee Sooyoung Teo'),
  ('Lee Yi-kyung'),
  ('Lee Yo-won'),
  ('Lee Yong-jin'),
  ('Lee Yoo-mi'),
  ('Lee Yoo-ri'),
  ('Lee Yoo-ri Seungri'),
  ('Lee Young-ji'),
  ('Lee Young-ji Solar'),
  ('Lim Ji-yeon'),
  ('Lim Ju-hwan'),
  ('Lim Yo-hwan'),
  ('Lizzy'),
  ('Ma Sun-ho'),
  ('Manny Pacquiao'),
  ('Manny Pacquiao Ryan'),
  ('Max Changmin'),
  ('Min Hyo-rin'),
  ('Min Kyung-hoon Niel'),
  ('Min-ah Yu-ra'),
  ('Mina'),
  ('Mina Dahyun Chaeyoung'),
  ('Mina Dahyun Chaeyoung Tzuyu'),
  ('Minzy Park Bom'),
  ('Mirani Park Cho-rong'),
  ('Miyeon'),
  ('Miyeon Soyeon'),
  ('Moon Chae-won'),
  ('Moon Geun-young Max'),
  ('Moon Hee-joon'),
  ('Moon Hee-joon So-you'),
  ('Moon Joon-young'),
  ('Moon Jung-hee'),
  ('Moon So-ri'),
  ('Nam Bo-ra'),
  ('Nam Chang-hee'),
  ('Nam Hee-suk'),
  ('Nam Ji-hyun'),
  ('Nam Ji-hyun Yerin'),
  ('Nam Joo-hyuk'),
  ('Nam Minhyuk'),
  ('Nam Tae-hyun'),
  ('Nam Woo-hyun'),
  ('Narsha'),
  ('Nayeon Jeongyeon Momo Sana Jihyo'),
  ('Nichkhun'),
  ('Niel Ryeowook Sohyun'),
  ('Noh Do-hee'),
  ('Noh Ji-sim Taemi'),
  ('Noh Sa-yeon'),
  ('Noh Sa-yeon Rowoon'),
  ('Noh Sa-yeon Uee'),
  ('Noh Sang-hyun'),
  ('Noh Woo-jin'),
  ('Oh Ha-young'),
  ('Oh Ha-young Son Na-eun Son Yeo-eun'),
  ('Oh Hyun-kyung'),
  ('Oh Ji-ho'),
  ('Oh Ji-young'),
  ('Oh Man-seok'),
  ('Oh Sang-jin'),
  ('Oh Sang-uk'),
  ('Oh Yeon-seo'),
  ('Oh Yeon-seo Soo Ae'),
  ('Oh Yeon-soo'),
  ('Ok Ja-yeon'),
  ('P.O'),
  ('Parc Jae-jung Wonstein'),
  ('Park'),
  ('Park Ah-in Roh Jeong-eui'),
  ('Park Bo-'),
  ('Park Bo-young'),
  ('Park Cho-rong'),
  ('Park Cho-rong Son Na-eun'),
  ('Park Chul-min'),
  ('Park Eun-seok'),
  ('Park Eun-tae'),
  ('Park Geun-shik'),
  ('Park Gun-hyung'),
  ('Park Ha-na'),
  ('Park Han-byul'),
  ('Park Hee-soon'),
  ('Park Hoon'),
  ('Park Hye-jeong'),
  ('Park Hyo-joo'),
  ('Park Ji-hu'),
  ('Park Ji-hyun'),
  ('Park Ji-sung'),
  ('Park Ji-sung IU'),
  ('Park Ji-won'),
  ('Park Ji-yoon'),
  ('Park Jin-joo Umji'),
  ('Park Jin-young'),
  ('Park Joon-hyung'),
  ('Park Joong-hoon'),
  ('Park Ju-hyun'),
  ('Park Jun-gyu'),
  ('Park Jung-chul'),
  ('Park Jung-in'),
  ('Park Jung-min'),
  ('Park Kangnam Yiren'),
  ('Park Ki-woong'),
  ('Park Kyuhyun RM'),
  ('Park Kyung-hye'),
  ('Park Mi-sun'),
  ('Park Mi-sun Son Yeon-jae Ye Ji-won Yura'),
  ('Park Myeong-ho'),
  ('Park Myung-soo'),
  ('Park Na-rae'),
  ('Park Na-rae Stephanie'),
  ('Park Nam-jung'),
  ('Park Sang-myun'),
  ('Park Sang-myun Sayuri Fujita'),
  ('Park Sang-won'),
  ('Park Se-ri'),
  ('Park Seo-joon'),
  ('Park Seo-joon Son Hyun-joo'),
  ('Park Seo-joon Yura'),
  ('Park Shin-hye'),
  ('Park Shin-yang'),
  ('Park So-hyun'),
  ('Park Soo-hong'),
  ('Park Sung-woong'),
  ('Park Tae-hwan Son Yeon-jae'),
  ('Park Ye-jin'),
  ('Park Yoo-na Tiffany'),
  ('Park Young-'),
  ('Pyo Chang-won'),
  ('Pyo Ye-jin'),
  ('Rami Rora'),
  ('Rei'),
  ('Roh Yoon-seo'),
  ('Ryu Dam Shindong'),
  ('Ryu Hyun-jin'),
  ('Ryu Hyun-jin Suzy'),
  ('Ryu Hyun-kyung'),
  ('Ryu Seung-ryong'),
  ('Ryu Seung-soo'),
  ('Sandara Park'),
  ('Se-hun'),
  ('Seo'),
  ('Seo Eun-kwang'),
  ('Seo Eun-soo'),
  ('Seo Ha-joon'),
  ('Seo Hyo-rim'),
  ('Seo Hyun-jin'),
  ('Seo In-guk Son Ho-jun'),
  ('Seo Jang-hoon'),
  ('Seo Ji-hoon Zico'),
  ('Seo Ji-hye'),
  ('Seo Kang-joon'),
  ('Seo Myun-ho Gummy'),
  ('Seo Ye-ji'),
  ('Seo Young-hee'),
  ('Seo-hyun'),
  ('Seok-jin''s guests'),
  ('Seol In-ah'),
  ('Seung-ho Yoo Su-bin'),
  ('Seung-ri'),
  ('Shim Eun-kyung'),
  ('Shim Eun-woo'),
  ('Shim Hyung-rae'),
  ('Shin Bong-sun'),
  ('Shin Da-eun'),
  ('Shin Dong-mi'),
  ('Shin Dong-min'),
  ('Shin Gi-ru Shindong'),
  ('Shin Jung-geun'),
  ('Shin Min-a'),
  ('Shin Se-kyung'),
  ('Shin Seung-ho'),
  ('Shin Soo-ji'),
  ('Shin Sung-rok'),
  ('Shin Ye-eun'),
  ('Si-wan'),
  ('Si-wan Yeo Jin-goo'),
  ('Sihyeon'),
  ('Simon'),
  ('Simon Dominic'),
  ('So-you'),
  ('Solbin Yang Se-chan'),
  ('Solji'),
  ('Son Byong-ho'),
  ('Son Dam-bi'),
  ('Son Ho-jun'),
  ('Son Hyun-joo'),
  ('Son Na-eun'),
  ('Son Na-eun Tae Hang-ho'),
  ('Son Ye-jin'),
  ('Song'),
  ('Song Chang-eui Suzy'),
  ('Song Chong-gug'),
  ('Song Eun-i'),
  ('Song Eun-yi'),
  ('Song Ga-yeon'),
  ('Song Hae-na'),
  ('Song Jae-rim'),
  ('Song Ji-in'),
  ('Song Jin-woo'),
  ('Song Joong-ki'),
  ('Song Kyung Ah'),
  ('Song Min-ho'),
  ('Stephanie'),
  ('Sung Dong-il'),
  ('Sung Hoon'),
  ('Sung-hoon'),
  ('Sung-kyu'),
  ('Sung-kyu Jin-young'),
  ('Sunmi'),
  ('Sunny'),
  ('Suzy'),
  ('T.O.P'),
  ('Tae-yeon'),
  ('Tae-yeon Tiffany Yoona Yuri'),
  ('Taecyeon'),
  ('Taeyang'),
  ('Tiger JK'),
  ('Tony Ahn'),
  ('Tony An'),
  ('Tzuyu'),
  ('Tzuyu Yeo Jin-goo'),
  ('U-Know Yunho'),
  ('Uee'),
  ('Uhm Hyun-kyung'),
  ('Uhm Ji-won'),
  ('Uhm Jung-hwa'),
  ('Uhm Ki-joon'),
  ('Verbal Jint'),
  ('Wax'),
  ('Woo'),
  ('Woo Do-hwan'),
  ('Woo Shoo Taecyeon'),
  ('Wook-min'),
  ('Wooyoung'),
  ('Yang Dong-geun'),
  ('Yang Jung-ah'),
  ('Yang Se-chan'),
  ('Yang Se-hyung'),
  ('Yang Se-jong'),
  ('Ye Ji-won'),
  ('Yeeun'),
  ('Yeji'),
  ('Yeon Jung-hoon'),
  ('Yeum Hye-seon'),
  ('Yoo'),
  ('Yoo Ah-in'),
  ('Yoo Byung-jae'),
  ('Yoo Hae-jin'),
  ('Yoo Hae-jin Yum Jung-ah'),
  ('Yoo Hee-kwan'),
  ('Yoo In-young'),
  ('Yoo Jun-sang'),
  ('Yoo Seung-ho'),
  ('Yoo Sun'),
  ('Yoo Yeon-seok'),
  ('Yoo Yul'),
  ('Yook Joong-wan'),
  ('Yoon'),
  ('Yoon Bo-mi'),
  ('Yoon Bo-mi Nucksal'),
  ('Yoon Bo-ra'),
  ('Yoon Do-hyun'),
  ('Yoon Doo-joon'),
  ('Yoon Hyung-bin'),
  ('Yoon Je-moon'),
  ('Yoon Jin-seo'),
  ('Yoon Jong-hoon'),
  ('Yoon Jong-shin'),
  ('Yoon Kyung-ho'),
  ('Yoon Mi-rae'),
  ('Yoon San-ha'),
  ('Yoon Se-ah'),
  ('Yoon Seok-ho'),
  ('Yoon Shi-yoon'),
  ('Yoon So-hee'),
  ('Yoon So-yi'),
  ('Yoona'),
  ('Young-ji'),
  ('Yubin'),
  ('Yun Sung-bin'),
  ('Yura'),
  ('Yuri'),
  ('Zico'),
  ('gyu Rain'),
  ('hyun'),
  ('jin'),
  ('na Seol In-ah'),
  ('nam'),
  ('woo Kim Tae-gyun'),
  ('young');

-- Step 3: Link guests to episodes
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=10 AND g.name_romanized='Cha Tae-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=10 AND g.name_romanized='Yoon Se-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=11 AND g.name_romanized='Jung Yong-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=11 AND g.name_romanized='Kim Je-dong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=13 AND g.name_romanized='Jang Dong-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=13 AND g.name_romanized='Lizzy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=14 AND g.name_romanized='Lizzy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=15 AND g.name_romanized='Kim Kwang-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=15 AND g.name_romanized='Tony An';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=16 AND g.name_romanized='Yuri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=17 AND g.name_romanized='Go Joo-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=17 AND g.name_romanized='Jung Yong-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=18 AND g.name_romanized='Lizzy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=19 AND g.name_romanized='Nichkhun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=19 AND g.name_romanized='Lizzy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=20 AND g.name_romanized='Kim Hee-chul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=21 AND g.name_romanized='Kim Je-dong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=22 AND g.name_romanized='Choi Si-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=22 AND g.name_romanized='Kim Min-jong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=23 AND g.name_romanized='Shim Hyung-rae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=24 AND g.name_romanized='Lee Kyung-shil';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=24 AND g.name_romanized='Song Eun-i';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=25 AND g.name_romanized='Park Bo-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=26 AND g.name_romanized='Jung Jin-';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=27 AND g.name_romanized='Max Changmin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=27 AND g.name_romanized='U-Know Yunho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=28 AND g.name_romanized='Kim Byung-man';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=30 AND g.name_romanized='Seung-ri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=31 AND g.name_romanized='Hyun Young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=32 AND g.name_romanized='Kim Kwang-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=32 AND g.name_romanized='Tony Ahn';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=33 AND g.name_romanized='Oh Ji-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=34 AND g.name_romanized='Park Jun-gyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=34 AND g.name_romanized='Uee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=35 AND g.name_romanized='Dae-sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=35 AND g.name_romanized='Jung Yong-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=37 AND g.name_romanized='Park Ye-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=39 AND g.name_romanized='Sunny';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=39 AND g.name_romanized='Yoona';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=40 AND g.name_romanized='Nichkhun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=40 AND g.name_romanized='Taecyeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=41 AND g.name_romanized='Lee Sun-kyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=41 AND g.name_romanized='Park Joong-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=43 AND g.name_romanized='Shin Bong-sun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=44 AND g.name_romanized='Jang Hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=46 AND g.name_romanized='Kim Hyun-joong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=49 AND g.name_romanized='Goo Ha-ra';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=49 AND g.name_romanized='Noh Sa-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=50 AND g.name_romanized='Kim Min-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=50 AND g.name_romanized='Nichkhun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=52 AND g.name_romanized='Choi Min-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=52 AND g.name_romanized='Yoon So-yi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=54 AND g.name_romanized='Choi Kang-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=54 AND g.name_romanized='Ji Sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=55 AND g.name_romanized='Ji-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=55 AND g.name_romanized='Suzy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=56 AND g.name_romanized='Ahn Mun-sook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=56 AND g.name_romanized='Kim Sook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=56 AND g.name_romanized='Shin Bong-sun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=56 AND g.name_romanized='Yang Jung-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=57 AND g.name_romanized='Cha Tae-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=57 AND g.name_romanized='Shin Se-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=59 AND g.name_romanized='Choiza';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=59 AND g.name_romanized='Gaeko';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=59 AND g.name_romanized='Simon Dominic';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=59 AND g.name_romanized='Tiger JK';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=59 AND g.name_romanized='Yoon Mi-rae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=61 AND g.name_romanized='Kang Ji-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=61 AND g.name_romanized='Kim Joo-hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=61 AND g.name_romanized='Lee Yeon-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=63 AND g.name_romanized='Hyo-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=63 AND g.name_romanized='Jessica';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=63 AND g.name_romanized='Seo-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=63 AND g.name_romanized='Tae-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=63 AND g.name_romanized='Yoona';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=63 AND g.name_romanized='Yuri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=65 AND g.name_romanized='Kim Joo-hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=65 AND g.name_romanized='Kim Sun-a';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=66 AND g.name_romanized='Kim Sun-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=66 AND g.name_romanized='Song Joong-ki';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=67 AND g.name_romanized='Kim Soo-ro';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=67 AND g.name_romanized='Park Ye-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=69 AND g.name_romanized='Choi Min-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=70 AND g.name_romanized='Lee Min-ki';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=70 AND g.name_romanized='Park Chul-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=70 AND g.name_romanized='Son Ye-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=71 AND g.name_romanized='Jo Hye-ryun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=71 AND g.name_romanized='Oh Yeon-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=72 AND g.name_romanized='Jung Yong-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=72 AND g.name_romanized='Lee Min-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=75 AND g.name_romanized='Choi Min-ho Hyo-rin Siwon Sohee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=76 AND g.name_romanized='Ji Jin-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=76 AND g.name_romanized='Joo Sang-wook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=76 AND g.name_romanized='Kim Sung-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=76 AND g.name_romanized='Lee Chun-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=78 AND g.name_romanized='Hong Soo-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=78 AND g.name_romanized='Lee Beom-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=79 AND g.name_romanized='Kim Je-dong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=79 AND g.name_romanized='Yoon Do-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=80 AND g.name_romanized='Go Ara Hyo-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=82 AND g.name_romanized='Lee Da-hae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=82 AND g.name_romanized='Oh Ji-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=84 AND g.name_romanized='Dae-sung G-Dragon Seung-ri Tae-yang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=84 AND g.name_romanized='T.O.P';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=86 AND g.name_romanized='Gaeko';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=86 AND g.name_romanized='Ha Ji-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=87 AND g.name_romanized='Han Ga-in';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=88 AND g.name_romanized='BoA';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=90 AND g.name_romanized='Lee Deok-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=90 AND g.name_romanized='Park Jun-gyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=90 AND g.name_romanized='Park Sang-myun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=92 AND g.name_romanized='Chun Jung-myung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=92 AND g.name_romanized='Park Jin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=93 AND g.name_romanized='Han Seung-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=93 AND g.name_romanized='Suzy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=95 AND g.name_romanized='Park Ji-sung IU';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=99 AND g.name_romanized='Im Ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=99 AND g.name_romanized='Lee Tae-gon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=100 AND g.name_romanized='Kim Hee-sun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=101 AND g.name_romanized='Kim Bum-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=101 AND g.name_romanized='Yoon Do-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=101 AND g.name_romanized='Yoon Jong-shin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=102 AND g.name_romanized='Kim Soo-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=103 AND g.name_romanized='Noh Sa-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=103 AND g.name_romanized='Shin Se-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=104 AND g.name_romanized='Eunhyuk Ham Eun-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=104 AND g.name_romanized='Jung Yong-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=104 AND g.name_romanized='Lee Joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=104 AND g.name_romanized='Nichkhun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=104 AND g.name_romanized='Si-wan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=104 AND g.name_romanized='Yoon Doo-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=105 AND g.name_romanized='Han Ji-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=105 AND g.name_romanized='Kim Je-dong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=107 AND g.name_romanized='Gaeko';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=107 AND g.name_romanized='Jang Shin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=107 AND g.name_romanized='Kim Sang-joong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=108 AND g.name_romanized='Gong Hyo-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=108 AND g.name_romanized='Lee Joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=109 AND g.name_romanized='Park Tae-hwan Son Yeon-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=111 AND g.name_romanized='Go Chang-suk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=111 AND g.name_romanized='Im Ha-ryong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=111 AND g.name_romanized='Lee Jong-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=111 AND g.name_romanized='Shin Jung-geun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=111 AND g.name_romanized='Son Byong-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=111 AND g.name_romanized='Tae-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=113 AND g.name_romanized='Jeon Mi-seon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=113 AND g.name_romanized='Yoo Hae-jin Yum Jung-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=114 AND g.name_romanized='Moon Geun-young Max';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=114 AND g.name_romanized='Changmin U-Know Yunho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=116 AND g.name_romanized='Ji Jin-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=116 AND g.name_romanized='Ji Sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=116 AND g.name_romanized='Song Chang-eui Suzy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=116 AND g.name_romanized='Yubin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=118 AND g.name_romanized='Choi Min-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=118 AND g.name_romanized='Park Bo-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=119 AND g.name_romanized='Choo Shin-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=119 AND g.name_romanized='Jin Se-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=119 AND g.name_romanized='Ryu Hyun-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=120 AND g.name_romanized='Lee Seung-gi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=120 AND g.name_romanized='Park Shin-hye';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=122 AND g.name_romanized='Goo Ha-ra';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=122 AND g.name_romanized='Jung Won-gwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=122 AND g.name_romanized='Kim Tae-hyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=122 AND g.name_romanized='Lee Sang-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=122 AND g.name_romanized='Kang Susie';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=122 AND g.name_romanized='Kim Wan-sun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=122 AND g.name_romanized='Park Nam-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=123 AND g.name_romanized='Go Soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=123 AND g.name_romanized='Han Hyo-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=125 AND g.name_romanized='Jeong Hyeong-don Juvie Train';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=125 AND g.name_romanized='Park Sang-myun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=125 AND g.name_romanized='Ryu Dam Shindong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=126 AND g.name_romanized='Choi Ji-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=127 AND g.name_romanized='Choi Ji-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=127 AND g.name_romanized='Jung Yong-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=127 AND g.name_romanized='Lee Jong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=127 AND g.name_romanized='Lee Gi-kwang Simon Dominic';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=128 AND g.name_romanized='Park Shin-yang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=128 AND g.name_romanized='Uhm Ji-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=129 AND g.name_romanized='Choi Min-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=129 AND g.name_romanized='Jung Yong-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=129 AND g.name_romanized='Lee Jong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=129 AND g.name_romanized='Kwang-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=129 AND g.name_romanized='L Lee Joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=131 AND g.name_romanized='Choo Sung-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=131 AND g.name_romanized='Lee Si-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=132 AND g.name_romanized='Hwang Jung-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=132 AND g.name_romanized='Hyuna';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=133 AND g.name_romanized='Han Hye-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=133 AND g.name_romanized='Lee Dong-wook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=135 AND g.name_romanized='Jackie Chan Siwon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=136 AND g.name_romanized='Han Hye-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=136 AND g.name_romanized='Lee Dong-wook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=137 AND g.name_romanized='Noh Sa-yeon Uee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=138 AND g.name_romanized='Kim Soo-ro';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=138 AND g.name_romanized='Kim Woo-bin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=138 AND g.name_romanized='Lee Jong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=138 AND g.name_romanized='Lee Jong-suk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=138 AND g.name_romanized='Min Hyo-rin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=139 AND g.name_romanized='Go Ara';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=139 AND g.name_romanized='Lee Yeon-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=141 AND g.name_romanized='Eun Ji-won Jessica';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=142 AND g.name_romanized='Lee Bo-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=142 AND g.name_romanized='Lee Sang-yoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=143 AND g.name_romanized='Kim In-kwon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=143 AND g.name_romanized='Lee Kyung-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=143 AND g.name_romanized='Ryu Hyun-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=144 AND g.name_romanized='Cha In-pyo Ricky';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=144 AND g.name_romanized='Kim';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=144 AND g.name_romanized='Seo Jang-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=145 AND g.name_romanized='Jeon Hye-bin Jeong Jinwoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=145 AND g.name_romanized='Kim Byung-man';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=145 AND g.name_romanized='Noh Woo-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=145 AND g.name_romanized='Park Jung-chul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=146 AND g.name_romanized='Kim Sang-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=146 AND g.name_romanized='Uhm Jung-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=147 AND g.name_romanized='Kim Soo-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=147 AND g.name_romanized='Lee Hyun-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=148 AND g.name_romanized='Jeong Jun-ha So Yi-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=149 AND g.name_romanized='Kim Soo-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=149 AND g.name_romanized='Kim Sook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=149 AND g.name_romanized='Kwon Ri-se';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=149 AND g.name_romanized='Park So-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=149 AND g.name_romanized='Song Eun-i';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=150 AND g.name_romanized='Chansung Taecyeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=150 AND g.name_romanized='Choo Sung-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=150 AND g.name_romanized='Jung Doo-hong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=151 AND g.name_romanized='Han Hyo-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=151 AND g.name_romanized='Jung Woo-sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=151 AND g.name_romanized='Junho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=153 AND g.name_romanized='Koo Ja-cheol';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=155 AND g.name_romanized='Suzy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=156 AND g.name_romanized='Minzy Park Bom';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=156 AND g.name_romanized='Sandara Park';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=156 AND g.name_romanized='Taeyang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=157 AND g.name_romanized='Ahn Gil-kang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=157 AND g.name_romanized='Jung Woong-in';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=157 AND g.name_romanized='Kim Hee-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=158 AND g.name_romanized='Jeon Mi-seon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=158 AND g.name_romanized='Moon Jung-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=158 AND g.name_romanized='Son Hyun-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=159 AND g.name_romanized='Jo Jung-chi John';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=159 AND g.name_romanized='Park Jung-in';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=159 AND g.name_romanized='Kim Kwang-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=159 AND g.name_romanized='Kim Ye-rim';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=159 AND g.name_romanized='Park Sang-myun Sayuri Fujita';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=160 AND g.name_romanized='Andy Eric';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=160 AND g.name_romanized='Hye-sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=162 AND g.name_romanized='Chansung Wooyoung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=162 AND g.name_romanized='Da-som Hyo-rin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=162 AND g.name_romanized='Jung Eun-ji Son Na-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=162 AND g.name_romanized='Sung-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=162 AND g.name_romanized='Lee Gi-kwang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=162 AND g.name_romanized='Yoon Doo-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=162 AND g.name_romanized='Lee Joon Seung-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=162 AND g.name_romanized='Min-ah Yu-ra';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=163 AND g.name_romanized='Dae-sung G-Dragon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=163 AND g.name_romanized='Seung-ri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=164 AND g.name_romanized='Kim Hae-sook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=164 AND g.name_romanized='Yoo Ah-in';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=166 AND g.name_romanized='Choi Jin-hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=166 AND g.name_romanized='Kim Woo-bin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=166 AND g.name_romanized='Park Shin-hye';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=167 AND g.name_romanized='Chun Jung-myung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=167 AND g.name_romanized='Kim Min-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=168 AND g.name_romanized='Park Myung-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=169 AND g.name_romanized='Joo Sang-wook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=169 AND g.name_romanized='Yang Dong-geun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=170 AND g.name_romanized='Kim Yoo-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=170 AND g.name_romanized='T.O.P';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=170 AND g.name_romanized='Yoon Je-moon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=171 AND g.name_romanized='Ryu Hyun-jin Suzy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=174 AND g.name_romanized='Bo-ra';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=174 AND g.name_romanized='Han Hye-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=174 AND g.name_romanized='Lee Seung-gi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=175 AND g.name_romanized='Gong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=175 AND g.name_romanized='Yoo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=175 AND g.name_romanized='Park Hee-soon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=176 AND g.name_romanized='Jang Ki-ha Jun Hyun-moo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=176 AND g.name_romanized='Kim Kwang-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=176 AND g.name_romanized='Lee Juck Muzie';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=177 AND g.name_romanized='Gil';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=179 AND g.name_romanized='Jae-kyung John';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=179 AND g.name_romanized='Park';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=179 AND g.name_romanized='Kim Kyung-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=179 AND g.name_romanized='Lee Dong-wook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=179 AND g.name_romanized='Park Soo-hong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=179 AND g.name_romanized='Song Kyung Ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=179 AND g.name_romanized='Sung-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=181 AND g.name_romanized='Lee Jong-suk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=181 AND g.name_romanized='Lee Se-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=181 AND g.name_romanized='Park Bo-';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=181 AND g.name_romanized='young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=182 AND g.name_romanized='Do-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=182 AND g.name_romanized='Si-wan Yeo Jin-goo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=183 AND g.name_romanized='Jo Min-su';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=183 AND g.name_romanized='Moon So-ri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=183 AND g.name_romanized='Uhm Jung-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=184 AND g.name_romanized='Baro';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=184 AND g.name_romanized='Kang Ye-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=184 AND g.name_romanized='Park Seo-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=184 AND g.name_romanized='Seo In-guk Son Ho-jun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=186 AND g.name_romanized='Jung Yong-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=186 AND g.name_romanized='Kang Min-hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=186 AND g.name_romanized='Lee Jong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=186 AND g.name_romanized='Lee Jung-shin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=186 AND g.name_romanized='Shim Eun-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=188 AND g.name_romanized='Kim Woo-bin Rain';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=190 AND g.name_romanized='Gong Hyung-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=190 AND g.name_romanized='Kang Ha-neul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=190 AND g.name_romanized='Kim Ji-seok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=190 AND g.name_romanized='Ku Hye-sun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=190 AND g.name_romanized='Kwon Hae-hyo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=190 AND g.name_romanized='Lee Sang-yoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=190 AND g.name_romanized='Seung-ri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=191 AND g.name_romanized='Kim Woo-bin Rain';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=192 AND g.name_romanized='Kim Dong-jun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=192 AND g.name_romanized='Kim Jung-nan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=192 AND g.name_romanized='Kim Min-jong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=192 AND g.name_romanized='Lee Sang-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=192 AND g.name_romanized='Lim Ju-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=192 AND g.name_romanized='Oh Man-seok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=192 AND g.name_romanized='Ryu Seung-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=195 AND g.name_romanized='Chansung Jun. K Junho Nichkhun Wooyoung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=195 AND g.name_romanized='Minzy Park Bom';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=195 AND g.name_romanized='Sandara Park';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=198 AND g.name_romanized='Choi Hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=198 AND g.name_romanized='Ha Yeon-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=198 AND g.name_romanized='Han Hye-jin Jin Se-yeon Min-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=198 AND g.name_romanized='Narsha';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=198 AND g.name_romanized='Park Seo-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=199 AND g.name_romanized='Park Ji-sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=201 AND g.name_romanized='Bo-ra Chansung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=201 AND g.name_romanized='Choi Min-ho Hoya';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=201 AND g.name_romanized='Sung-kyu Jin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=201 AND g.name_romanized='Kang Min-hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=202 AND g.name_romanized='Baek Sung-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=202 AND g.name_romanized='Cha Yu-ram Fabien';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=202 AND g.name_romanized='Heo Kyung-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=202 AND g.name_romanized='Ji Sung Ju Ji-hoon Sam Okyere Son Na-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=202 AND g.name_romanized='Yoon Bo-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=204 AND g.name_romanized='Ryu Seung-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=205 AND g.name_romanized='Baek Ji-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=205 AND g.name_romanized='Fei Hong Jin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=205 AND g.name_romanized='Kang Seung-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=205 AND g.name_romanized='Lee Guk-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=206 AND g.name_romanized='Hong Seok-cheon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=206 AND g.name_romanized='Joo Won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=207 AND g.name_romanized='Heechul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=207 AND g.name_romanized='Kim Je-dong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=207 AND g.name_romanized='Lee So-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=207 AND g.name_romanized='Nam Hee-suk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=207 AND g.name_romanized='Park Soo-hong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=208 AND g.name_romanized='Suzy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=209 AND g.name_romanized='Chun Myung-hoon Danny';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=209 AND g.name_romanized='Ahn';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=209 AND g.name_romanized='Eun Ji-won Kai';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=209 AND g.name_romanized='Se-hun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=209 AND g.name_romanized='Lee Tae-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=209 AND g.name_romanized='Moon Hee-joon So-you';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=210 AND g.name_romanized='Choi Bu-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=210 AND g.name_romanized='Kim Hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=210 AND g.name_romanized='Kim Won-hyo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=210 AND g.name_romanized='Lee Hye-jeong Seolhyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=210 AND g.name_romanized='Wooyoung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=210 AND g.name_romanized='Yook Joong-wan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=211 AND g.name_romanized='Ailee Lim Seul-ong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=211 AND g.name_romanized='Ji Chang-wook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=211 AND g.name_romanized='Kim Tae-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=211 AND g.name_romanized='Lee Sung-jae Skull';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=211 AND g.name_romanized='Song Eun-yi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=213 AND g.name_romanized='Choi Yeo-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=213 AND g.name_romanized='Kim Min-seo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=213 AND g.name_romanized='Lee Yoo-ri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=213 AND g.name_romanized='Seo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=213 AND g.name_romanized='Woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=213 AND g.name_romanized='Yoo In-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=214 AND g.name_romanized='Alex';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=214 AND g.name_romanized='Park Young-';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=214 AND g.name_romanized='gyu Rain';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=215 AND g.name_romanized='Jo Jung-suk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=215 AND g.name_romanized='Shin Min-a';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=217 AND g.name_romanized='Jo Jin-woong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=217 AND g.name_romanized='Kim Sung-kyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=217 AND g.name_romanized='Oh Sang-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=218 AND g.name_romanized='Jung Eun-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=218 AND g.name_romanized='Kim Ji-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=218 AND g.name_romanized='Oh Yeon-seo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=219 AND g.name_romanized='Han Sang-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=219 AND g.name_romanized='Han Ye-seul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=219 AND g.name_romanized='Joo Sang-wook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=219 AND g.name_romanized='Jung Gyu-woon Wang Ji-hye';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=220 AND g.name_romanized='Jang Dong-min Kangnam';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=220 AND g.name_romanized='Kim Min-kyo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=220 AND g.name_romanized='Park Soo-hong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=220 AND g.name_romanized='Song Jae-rim';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=221 AND g.name_romanized='Bobby';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=221 AND g.name_romanized='Kim';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=221 AND g.name_romanized='Hong Jin-young Jung-in';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=221 AND g.name_romanized='Kim Kyung-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=221 AND g.name_romanized='Kim Yeon-woo Kyuhyun Leeteuk Narsha';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=224 AND g.name_romanized='Han Groo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=224 AND g.name_romanized='Jeon So-min Kyung Soo-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=224 AND g.name_romanized='Lee Sung-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=224 AND g.name_romanized='Song Ga-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=225 AND g.name_romanized='Kim Woo-bin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=225 AND g.name_romanized='Lee Hyun-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=226 AND g.name_romanized='Kang Hye-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=226 AND g.name_romanized='Kim Hye-ja';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=226 AND g.name_romanized='Lee Chun-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=227 AND g.name_romanized='Kang Jung-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=227 AND g.name_romanized='Ryu Hyun-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=228 AND g.name_romanized='Lee Seung-gi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=228 AND g.name_romanized='Moon Chae-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=228 AND g.name_romanized='Lee Seo-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=230 AND g.name_romanized='Choi Tae-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=230 AND g.name_romanized='Hong Jong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=230 AND g.name_romanized='Nam Joo-hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=230 AND g.name_romanized='Seo Ha-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=230 AND g.name_romanized='Seo Kang-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=232 AND g.name_romanized='Hong Kyung-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=232 AND g.name_romanized='Kim Ji-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=232 AND g.name_romanized='Kim Won-jun Miryo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=232 AND g.name_romanized='Oh Hyun-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=232 AND g.name_romanized='Park Ji-yoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=232 AND g.name_romanized='Shin Da-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=233 AND g.name_romanized='Dongwoo Dongwoon Eric';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=233 AND g.name_romanized='Nam Minhyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=233 AND g.name_romanized='Niel Ryeowook Sohyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=234 AND g.name_romanized='Fei Kim Sung-ryung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=234 AND g.name_romanized='Seo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=234 AND g.name_romanized='Woo Shoo Taecyeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=234 AND g.name_romanized='Yeon Jung-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=234 AND g.name_romanized='Yoo Sun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=236 AND g.name_romanized='Andy Dong-wan Eric';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=236 AND g.name_romanized='Hye-sung Jun Jin Min-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=236 AND g.name_romanized='Jung Hee-chul Hyung-sik';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=236 AND g.name_romanized='Moon Joon-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=236 AND g.name_romanized='Kim Dong-jun Kwang-hee Tae-heon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=237 AND g.name_romanized='Hani';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=237 AND g.name_romanized='Jung So-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=237 AND g.name_romanized='Nam Ji-hyun Yerin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=237 AND g.name_romanized='Yoon So-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=238 AND g.name_romanized='Kim Seo-hyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=238 AND g.name_romanized='Ye Ji-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=239 AND g.name_romanized='Kim Dong-hyun Sung Si-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=240 AND g.name_romanized='Junho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=240 AND g.name_romanized='Kang Ha-neul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=240 AND g.name_romanized='Kim Woo-bin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=241 AND g.name_romanized='Park Ye-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=241 AND g.name_romanized='Shin Se-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=241 AND g.name_romanized='Yoon Jin-seo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=242 AND g.name_romanized='Jung Il-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=242 AND g.name_romanized='Jung Yong-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=242 AND g.name_romanized='Lee Hong-gi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=243 AND g.name_romanized='Hong Jong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=243 AND g.name_romanized='Jang Su-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=243 AND g.name_romanized='Kang Kyun-sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=243 AND g.name_romanized='Son Ho-jun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=243 AND g.name_romanized='Yoo Byung-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=244 AND g.name_romanized='Choa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=244 AND g.name_romanized='Jang Do-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=244 AND g.name_romanized='Jessi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=244 AND g.name_romanized='Kim Yoo-ri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=244 AND g.name_romanized='Seo Ye-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=245 AND g.name_romanized='Jinu Sean';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=246 AND g.name_romanized='Park Seo-joon Son Hyun-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=248 AND g.name_romanized='Henry Kangnam';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=248 AND g.name_romanized='Nichkhun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=248 AND g.name_romanized='Park Joon-hyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=249 AND g.name_romanized='Kim Jun-hyun Uee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=250 AND g.name_romanized='Dae-sung G-Dragon Seung-ri Tae-yang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=250 AND g.name_romanized='T.O.P';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=251 AND g.name_romanized='Byul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=251 AND g.name_romanized='Kim So-hyun Son Jun-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=252 AND g.name_romanized='Eun Ji-won Jay';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=252 AND g.name_romanized='Park';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=252 AND g.name_romanized='Jessi San E';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=252 AND g.name_romanized='Verbal Jint';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=253 AND g.name_romanized='Do Sang-woo Hae-ryung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=253 AND g.name_romanized='Hwang Seung-eon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=253 AND g.name_romanized='Irene';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=253 AND g.name_romanized='Kim';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=253 AND g.name_romanized='Park Ha-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=253 AND g.name_romanized='Seo Hyun-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=253 AND g.name_romanized='Jang Ye-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=254 AND g.name_romanized='Hyo-yeon Seo-hyun Soo-young Sunny';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=254 AND g.name_romanized='Tae-yeon Tiffany Yoona Yuri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=255 AND g.name_romanized='Bo-ra So-you';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=255 AND g.name_romanized='Lee Guk-joo Seolhyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=255 AND g.name_romanized='Yoon Bo-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=256 AND g.name_romanized='Baek Jin-hee Chansung Jun. K Junho Nichkhun Taecyeon Wooyoung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=257 AND g.name_romanized='Hong Jin-ho Hyun Joo-yup';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=257 AND g.name_romanized='Kim Yeon-koung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=257 AND g.name_romanized='Shin Soo-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=257 AND g.name_romanized='Song Chong-gug';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=258 AND g.name_romanized='Hwang Jung-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=258 AND g.name_romanized='Jang Yoon-ju';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=258 AND g.name_romanized='Jung Man-sik';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=259 AND g.name_romanized='Cha Ye-ryun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=259 AND g.name_romanized='Lee Yo-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=260 AND g.name_romanized='Kim Gun-mo Koo Jun-yup';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=260 AND g.name_romanized='Lee Ha-neul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=260 AND g.name_romanized='Lee Jae-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=260 AND g.name_romanized='Park Joon-hyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=262 AND g.name_romanized='Kang Sung-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=262 AND g.name_romanized='Kim Min-kyo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=262 AND g.name_romanized='Kim Soo-ro';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=262 AND g.name_romanized='Nam Bo-ra';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=262 AND g.name_romanized='Park Gun-hyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=263 AND g.name_romanized='Lee Dong-wook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=263 AND g.name_romanized='Park Seo-joon Yura';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=264 AND g.name_romanized='Kwon Sang-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=264 AND g.name_romanized='Sung Dong-il';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=265 AND g.name_romanized='John';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=265 AND g.name_romanized='Park Kyuhyun RM';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=265 AND g.name_romanized='Yeeun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=266 AND g.name_romanized='Eun-hyuk Hong Jin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=266 AND g.name_romanized='Lim Ju-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=268 AND g.name_romanized='Gong Seung-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=268 AND g.name_romanized='Hwang Suk-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=268 AND g.name_romanized='Joy Jung Kyung-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=268 AND g.name_romanized='Kim Ja-in';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=268 AND g.name_romanized='Park Han-byul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=268 AND g.name_romanized='Park Na-rae Stephanie';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=268 AND g.name_romanized='Yoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=268 AND g.name_romanized='Park';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=269 AND g.name_romanized='Kim Hee-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=269 AND g.name_romanized='Lee Chun-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=269 AND g.name_romanized='Park Bo-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=271 AND g.name_romanized='Jung Doo-hong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=271 AND g.name_romanized='Kim Ki-tae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=271 AND g.name_romanized='Lee Won-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=271 AND g.name_romanized='Noh Ji-sim Taemi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=271 AND g.name_romanized='Jae-suk''s guests';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=271 AND g.name_romanized='Gary''s guests';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=271 AND g.name_romanized='Haha''s guests';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=271 AND g.name_romanized='Seok-jin''s guests';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=271 AND g.name_romanized='Jong-kook''s guests';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=271 AND g.name_romanized='Kwang-soo''s guests';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=273 AND g.name_romanized='woo Kim Tae-gyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=273 AND g.name_romanized='Hong Yoon-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=273 AND g.name_romanized='Kim Jeong-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=273 AND g.name_romanized='Kim Tae-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=273 AND g.name_romanized='Lee Eun-hyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=274 AND g.name_romanized='Jo Jung-chi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=274 AND g.name_romanized='Kim Kwang-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=274 AND g.name_romanized='Min Kyung-hoon Niel';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=274 AND g.name_romanized='Park Soo-hong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=275 AND g.name_romanized='Hani';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=275 AND g.name_romanized='Hong Jin-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=275 AND g.name_romanized='Kim Hee-chul Leeteuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=275 AND g.name_romanized='Lim Yo-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=278 AND g.name_romanized='Andy Bobby';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=278 AND g.name_romanized='B.I Chae-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=278 AND g.name_romanized='Kim Ji-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=278 AND g.name_romanized='Kim Jung-nam';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=278 AND g.name_romanized='Lee Ji-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=278 AND g.name_romanized='Lee Jong-soo Seolhyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=278 AND g.name_romanized='Stephanie';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=281 AND g.name_romanized='Lim Ji-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=282 AND g.name_romanized='Go Ah-sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=282 AND g.name_romanized='Lee Hee-joon Si-wan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=283 AND g.name_romanized='Ji So-yun Jong Tae-se';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=283 AND g.name_romanized='Park Ji-sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=286 AND g.name_romanized='Kim Ga-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=287 AND g.name_romanized='Ahn Gil-kang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=287 AND g.name_romanized='Kim Do-kyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=287 AND g.name_romanized='Kim Jo-han';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=287 AND g.name_romanized='Kim Won-hae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=287 AND g.name_romanized='Lee Hong-ryul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=287 AND g.name_romanized='Park Mi-sun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=287 AND g.name_romanized='Yoo Yul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=289 AND g.name_romanized='Jung Il-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=289 AND g.name_romanized='Lee Da-hae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=292 AND g.name_romanized='Hong Jin-ho Jeong Jeong-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=292 AND g.name_romanized='Kang Hyeon-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=292 AND g.name_romanized='Lee Wan Lizzy Mikey';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=292 AND g.name_romanized='Nam Chang-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=292 AND g.name_romanized='Park Myeong-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=292 AND g.name_romanized='Wax';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=294 AND g.name_romanized='Hyeri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=294 AND g.name_romanized='Nam Tae-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=294 AND g.name_romanized='Song Min-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=297 AND g.name_romanized='Eunseo Jin Goo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=297 AND g.name_romanized='Kim Ji-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=298 AND g.name_romanized='Go Ara';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=298 AND g.name_romanized='Kim Sung-kyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=298 AND g.name_romanized='Lee Je-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=299 AND g.name_romanized='Hong Jin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=299 AND g.name_romanized='Jo Bo-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=299 AND g.name_romanized='Kyung Soo-jin Stephanie';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=299 AND g.name_romanized='Lee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=299 AND g.name_romanized='Uhm Hyun-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=300 AND g.name_romanized='BTS';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=302 AND g.name_romanized='Nayeon Jeongyeon Momo Sana Jihyo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=302 AND g.name_romanized='Mina Dahyun Chaeyoung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=302 AND g.name_romanized='Tzuyu Yeo Jin-goo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=303 AND g.name_romanized='Ahn Sung-ki';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=303 AND g.name_romanized='Han Ye-ri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=303 AND g.name_romanized='Cho Jin-woong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=303 AND g.name_romanized='Kwon Yul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=305 AND g.name_romanized='Jo Se-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=305 AND g.name_romanized='Kim Dong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=305 AND g.name_romanized='Kim Jun-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=306 AND g.name_romanized='Kyungri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=306 AND g.name_romanized='Lee Ki-woo Nichkhun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=307 AND g.name_romanized='Bo-ra Da-som Hyo-rin So-you Shownu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=308 AND g.name_romanized='Ji Jin-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=308 AND g.name_romanized='Kim Hee-ae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=309 AND g.name_romanized='Hong Jin-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=309 AND g.name_romanized='Lee Ki-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=309 AND g.name_romanized='Seo Jang-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=310 AND g.name_romanized='Ha Jae-sook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=310 AND g.name_romanized='Oh Yeon-seo Soo Ae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=312 AND g.name_romanized='Bada Jo Jung-chi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=312 AND g.name_romanized='Kim Kyung-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=312 AND g.name_romanized='Yoo Byung-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=312 AND g.name_romanized='Yoon Jong-shin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=313 AND g.name_romanized='Ahn Mun-sook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=313 AND g.name_romanized='Ha Jae-sook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=313 AND g.name_romanized='Kim Se-jeong Mijoo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=313 AND g.name_romanized='Noh Sa-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=314 AND g.name_romanized='Hong Jong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=314 AND g.name_romanized='Kang Ha-neul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=314 AND g.name_romanized='Lee Joon-gi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=315 AND g.name_romanized='Cha Seung-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=317 AND g.name_romanized='Han Hye-jin Key';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=317 AND g.name_romanized='Kim Dong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=317 AND g.name_romanized='Lee Kyung-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=317 AND g.name_romanized='Moon Hee-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=317 AND g.name_romanized='Sung Hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=317 AND g.name_romanized='Yoon Hyung-bin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=320 AND g.name_romanized='Jo Yoon-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=320 AND g.name_romanized='Lee Joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=320 AND g.name_romanized='Lim Ji-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=320 AND g.name_romanized='Yoo Hae-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=321 AND g.name_romanized='Lee Kyu-han';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=321 AND g.name_romanized='Park Na-rae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=321 AND g.name_romanized='Park Soo-hong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=321 AND g.name_romanized='Solbin Yang Se-chan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=322 AND g.name_romanized='Kang Min-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=322 AND g.name_romanized='Park Mi-sun Son Yeon-jae Ye Ji-won Yura';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=323 AND g.name_romanized='Choi Min-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=323 AND g.name_romanized='Jang Do-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=323 AND g.name_romanized='Kim Jun-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=323 AND g.name_romanized='Seo Ji-hye';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=323 AND g.name_romanized='Yang Se-chan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=325 AND g.name_romanized='Gary';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=326 AND g.name_romanized='Eun Ji-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=326 AND g.name_romanized='Jang Su-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=326 AND g.name_romanized='Kang Sung-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=326 AND g.name_romanized='Kim Jae-duc';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=326 AND g.name_romanized='Lee Jai-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=326 AND g.name_romanized='Hwang Woo-seul-hye';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=327 AND g.name_romanized='D.O.';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=327 AND g.name_romanized='Jo Jung-suk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=328 AND g.name_romanized='Nayeon Jeongyeon Momo Sana Jihyo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=328 AND g.name_romanized='Mina Dahyun Chaeyoung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=328 AND g.name_romanized='Tzuyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=330 AND g.name_romanized='Jisoo Jennie Rosé Lisa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=331 AND g.name_romanized='Kim So-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=336 AND g.name_romanized='Gary';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=339 AND g.name_romanized='Heo Kyung-hwan KCM';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=339 AND g.name_romanized='Kim Won-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=339 AND g.name_romanized='Kim Yong-man';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=339 AND g.name_romanized='Lee Chun-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=343 AND g.name_romanized='Choi Tae-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=343 AND g.name_romanized='Jeon So-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=343 AND g.name_romanized='Kang Han-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=343 AND g.name_romanized='Lee Se-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=343 AND g.name_romanized='Park Jin-joo Umji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=344 AND g.name_romanized='Choi Min-yong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=344 AND g.name_romanized='Yoon Bo-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=345 AND g.name_romanized='Han Jae-suk Sandara';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=345 AND g.name_romanized='Park';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=345 AND g.name_romanized='Yoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=347 AND g.name_romanized='Jang Do-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=349 AND g.name_romanized='Hyo-rin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=355 AND g.name_romanized='Jung Hye-sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=357 AND g.name_romanized='Hong Jin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=357 AND g.name_romanized='Lee Sun-bin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=357 AND g.name_romanized='Lee Tae-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=357 AND g.name_romanized='Oh Ha-young Son Na-eun Son Yeo-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=360 AND g.name_romanized='Cheon Sung-moon Jeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=360 AND g.name_romanized='Wook-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=360 AND g.name_romanized='Jo Se-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=360 AND g.name_romanized='Kim Jong-myung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=360 AND g.name_romanized='Kim Soo-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=360 AND g.name_romanized='Park Geun-shik';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=360 AND g.name_romanized='Son Na-eun Tae Hang-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=362 AND g.name_romanized='Kang Ha-neul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=362 AND g.name_romanized='Park Seo-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=363 AND g.name_romanized='Hyo-yeon Soo-young Sunny';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=363 AND g.name_romanized='Tae-yeon Tiffany Yoona Yuri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=366 AND g.name_romanized='So-you';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=367 AND g.name_romanized='Baek Ji-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=367 AND g.name_romanized='Hwang Seung-eon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=367 AND g.name_romanized='Jo Se-ho Kei';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=367 AND g.name_romanized='Lee Elijah Solbi Sung Hoon Sunmi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=372 AND g.name_romanized='Shin Sung-rok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=372 AND g.name_romanized='Yoon Bo-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=374 AND g.name_romanized='Ha Yeon-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=374 AND g.name_romanized='Jo Se-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=374 AND g.name_romanized='Kang Daniel';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=374 AND g.name_romanized='Noh Sa-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=376 AND g.name_romanized='Donghae Eun-hyuk Leeteuk Yesung Irene';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=377 AND g.name_romanized='Im Se-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=377 AND g.name_romanized='Kim Ji-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=377 AND g.name_romanized='Kim Se-jeong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=377 AND g.name_romanized='Ko Sung-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=380 AND g.name_romanized='Kang Han-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=380 AND g.name_romanized='Kyung Soo-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=381 AND g.name_romanized='Choi Gwi-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=381 AND g.name_romanized='Go Bo-gyeol';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=381 AND g.name_romanized='Heo Sung-tae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=381 AND g.name_romanized='Lee Sang-yeob';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=383 AND g.name_romanized='Eun Ji-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=383 AND g.name_romanized='Jang Su-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=383 AND g.name_romanized='Kang Sung-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=383 AND g.name_romanized='Kim Jae-duc';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=383 AND g.name_romanized='Lee Jai-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=383 AND g.name_romanized='Lee Elijah So-you';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=388 AND g.name_romanized='Goo Hara';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=388 AND g.name_romanized='Kang Mi-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=388 AND g.name_romanized='Lee Da-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=388 AND g.name_romanized='Seol In-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=390 AND g.name_romanized='Heo Kyung-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=390 AND g.name_romanized='Lee Sang-yeob Shorry J';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=390 AND g.name_romanized='Yoo Byung-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=392 AND g.name_romanized='Hong Jin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=392 AND g.name_romanized='Kang Han-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=392 AND g.name_romanized='Lee Da-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=392 AND g.name_romanized='Lee Sang-yeob';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=402 AND g.name_romanized='Dayoung Hyejeong Seolhyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=402 AND g.name_romanized='JooE Kang Seung-yoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=402 AND g.name_romanized='Song Min-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=405 AND g.name_romanized='Lee Guk-joo Kyungri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=405 AND g.name_romanized='Seo Eun-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=405 AND g.name_romanized='Son Dam-bi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=406 AND g.name_romanized='Hong Jin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=406 AND g.name_romanized='Kang Han-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=406 AND g.name_romanized='Lee Da-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=406 AND g.name_romanized='Lee Sang-yeob';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=409 AND g.name_romanized='Han Eun-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=409 AND g.name_romanized='Hwang Chi-yeul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=409 AND g.name_romanized='Jennie Jisoo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=409 AND g.name_romanized='Pyo Ye-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=409 AND g.name_romanized='Yoon Bo-ra';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=410 AND g.name_romanized='Henry Cavill';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=410 AND g.name_romanized='Simon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=413 AND g.name_romanized='Jennie Jin Ki-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=414 AND g.name_romanized='Kim Roi-ha Kwak Si-yang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=414 AND g.name_romanized='Seo Hyo-rim';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=415 AND g.name_romanized='Noh Sa-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=417 AND g.name_romanized='B.I';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=417 AND g.name_romanized='Bobby';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=417 AND g.name_romanized='Kim Ji-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=417 AND g.name_romanized='Lee Elijah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=417 AND g.name_romanized='Lee Joo-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=417 AND g.name_romanized='Lee Si-a Seungri Sunmi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=419 AND g.name_romanized='Jang Do-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=422 AND g.name_romanized='Im Soo-hyang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=422 AND g.name_romanized='Lee Ha-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=424 AND g.name_romanized='Ahn Hyo-seop';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=424 AND g.name_romanized='Seo Young-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=424 AND g.name_romanized='Son Na-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=425 AND g.name_romanized='Kim Byeong-ok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=427 AND g.name_romanized='Irene Joy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=427 AND g.name_romanized='Kang Han-';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=427 AND g.name_romanized='na Seol In-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=428 AND g.name_romanized='Nayeon Jeongyeon Momo Sana Jihyo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=428 AND g.name_romanized='Mina Dahyun Chaeyoung Tzuyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=429 AND g.name_romanized='Byul Lee Si-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=433 AND g.name_romanized='Apink';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=435 AND g.name_romanized='Gong Myung Jin Seon-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=435 AND g.name_romanized='Lee Dong-hwi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=435 AND g.name_romanized='Lee Hanee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=435 AND g.name_romanized='Ryu Seung-ryong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=436 AND g.name_romanized='Hong Jong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=436 AND g.name_romanized='Jeong Yu-mi Jimin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=436 AND g.name_romanized='Mina';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=436 AND g.name_romanized='Lee Yoo-ri Seungri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=437 AND g.name_romanized='Go Ara';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=437 AND g.name_romanized='Jung Il-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=437 AND g.name_romanized='Kwon Yul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=437 AND g.name_romanized='Park Hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=442 AND g.name_romanized='Han Da-gam';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=442 AND g.name_romanized='Hong Jin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=442 AND g.name_romanized='Keum Sae-rok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=445 AND g.name_romanized='Bona';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=445 AND g.name_romanized='Jang Hee-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=445 AND g.name_romanized='Kim Jae-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=447 AND g.name_romanized='Ha Seok-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=447 AND g.name_romanized='Kim Ji-seok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=447 AND g.name_romanized='Lee Yi-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=448 AND g.name_romanized='Han Bo-reum Hani';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=448 AND g.name_romanized='Solji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=448 AND g.name_romanized='Kim Hye-yoon Mingyu Seungkwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=449 AND g.name_romanized='Esom Kim Kyung-';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=449 AND g.name_romanized='nam';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=450 AND g.name_romanized='Lee Dong-hwi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=453 AND g.name_romanized='Im Soo-hyang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=453 AND g.name_romanized='Lee Sang-yeob';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=457 AND g.name_romanized='Chungha Seol In-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=458 AND g.name_romanized='Code Kunst';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=458 AND g.name_romanized='Go Young-bae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=458 AND g.name_romanized='Lee Tae-wook Pyeon Yoo-il';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=458 AND g.name_romanized='Seo Myun-ho Gummy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=458 AND g.name_romanized='Jung Eun-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=458 AND g.name_romanized='Kim Nam-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=458 AND g.name_romanized='Oh Ha-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=458 AND g.name_romanized='Park Cho-rong Son Na-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=458 AND g.name_romanized='Yoon Bo-mi Nucksal';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=460 AND g.name_romanized='Jo Jung-suk Yoona';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=461 AND g.name_romanized='Jang Jin-hee Rothy Seunghee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=461 AND g.name_romanized='Song Ji-in';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=463 AND g.name_romanized='Bae Seong-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=463 AND g.name_romanized='Cho Yi-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=463 AND g.name_romanized='Kim Hye-jun Sung Dong-il';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=465 AND g.name_romanized='Choi Yu-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=465 AND g.name_romanized='Lim Ji-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=465 AND g.name_romanized='Park Jung-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=466 AND g.name_romanized='Jang Ye-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=466 AND g.name_romanized='Kim Ye-won Sunmi Sunny';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=468 AND g.name_romanized='Code Kunst';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=468 AND g.name_romanized='Go Young-bae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=468 AND g.name_romanized='Lee Tae-wook Pyeon Yoo-il';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=468 AND g.name_romanized='Seo Myun-ho Gummy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=468 AND g.name_romanized='Jung Eun-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=468 AND g.name_romanized='Kim Nam-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=468 AND g.name_romanized='Oh Ha-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=468 AND g.name_romanized='Park Cho-rong Son Na-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=468 AND g.name_romanized='Yoon Bo-mi Nucksal';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=471 AND g.name_romanized='Hwang Chi-yeul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=471 AND g.name_romanized='Kang Mi-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=471 AND g.name_romanized='Park Yoo-na Tiffany';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=474 AND g.name_romanized='Go Min-si';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=474 AND g.name_romanized='Hwang Bo-ra';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=475 AND g.name_romanized='Hong Hyun-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=475 AND g.name_romanized='Park Ji-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=476 AND g.name_romanized='Hyuna';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=476 AND g.name_romanized='Kang Han-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=476 AND g.name_romanized='Lee Guk-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=476 AND g.name_romanized='Sihyeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=480 AND g.name_romanized='Kang Han-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=480 AND g.name_romanized='Lee Hee-jin YooA';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=480 AND g.name_romanized='Yoo Byung-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=481 AND g.name_romanized='Chanmi Hyejeong Jimin Seolhyun Yuna';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=482 AND g.name_romanized='Hwang Bo-ra Ryan Reynolds Melanie Laurent Adria Arjona';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=483 AND g.name_romanized='Heo Kyung-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=483 AND g.name_romanized='Jun Hyo-seong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=483 AND g.name_romanized='Kang Tae-oh Yoyomi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=484 AND g.name_romanized='Heo Kyung-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=484 AND g.name_romanized='Jun Hyo-seong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=484 AND g.name_romanized='Kang Tae-oh YOYOMI';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=486 AND g.name_romanized='Kang Han-na Keum Sae-rok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=486 AND g.name_romanized='Lee Joo-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=486 AND g.name_romanized='Park Cho-rong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=488 AND g.name_romanized='Park Ha-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=490 AND g.name_romanized='Heo Kyung-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=490 AND g.name_romanized='Kang Han-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=493 AND g.name_romanized='Kang Tae-oh';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=493 AND g.name_romanized='Kim Na-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=493 AND g.name_romanized='Lee Na-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=493 AND g.name_romanized='Yura';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=494 AND g.name_romanized='Im Soo-hyang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=494 AND g.name_romanized='Jo Byung-gyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=495 AND g.name_romanized='Hwang Young-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=495 AND g.name_romanized='Kang Daniel';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=495 AND g.name_romanized='Lee Il-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=495 AND g.name_romanized='Park Mi-sun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=496 AND g.name_romanized='Lee Do-hyun Ong Seong-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=496 AND g.name_romanized='Seo Ji-hoon Zico';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=497 AND g.name_romanized='Hong Hyun-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=497 AND g.name_romanized='Kim Ji-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=498 AND g.name_romanized='Ahn Bo-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=498 AND g.name_romanized='Ji Yi-soo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=498 AND g.name_romanized='Lee Joo-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=498 AND g.name_romanized='Song Jin-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=499 AND g.name_romanized='Hong Jin-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=499 AND g.name_romanized='Jo Se-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=499 AND g.name_romanized='Lee Do-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=499 AND g.name_romanized='Noh Sa-yeon Rowoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=500 AND g.name_romanized='Choi Yoo-jung Chungha Mijoo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=500 AND g.name_romanized='Park Cho-rong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=500 AND g.name_romanized='Yoon Bo-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=501 AND g.name_romanized='Ha Yeon-joo Kwak Si-yang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=501 AND g.name_romanized='Lee Yi-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=501 AND g.name_romanized='Park Hyo-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=502 AND g.name_romanized='Jun Hyo-seong Mingyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=503 AND g.name_romanized='Ahn Ji-young BewhY Hyojung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=503 AND g.name_romanized='Jessi Lee Jin-hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=504 AND g.name_romanized='Kim Jae-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=504 AND g.name_romanized='Kim Min-kyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=504 AND g.name_romanized='Shim Eun-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=506 AND g.name_romanized='Nayeon Jeongyeon Momo Sana Jihyo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=506 AND g.name_romanized='Mina Dahyun Chaeyoung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=506 AND g.name_romanized='Tzuyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=507 AND g.name_romanized='Do Sang-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=507 AND g.name_romanized='Han Sun-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=507 AND g.name_romanized='Ji Chang-wook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=507 AND g.name_romanized='Kim You-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=509 AND g.name_romanized='Kang Han-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=509 AND g.name_romanized='Lee Sang-yeob';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=510 AND g.name_romanized='Jo Se-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=510 AND g.name_romanized='Lee Do-hyun Sunmi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=510 AND g.name_romanized='Zico';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=513 AND g.name_romanized='Jang Won-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=513 AND g.name_romanized='Kim Do-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=513 AND g.name_romanized='Kim Dong-jun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=513 AND g.name_romanized='Lee Mi-joo Soyou';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=514 AND g.name_romanized='Jeon So-mi Jessi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=514 AND g.name_romanized='Lee Young-ji Solar';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=515 AND g.name_romanized='Ha Do-kwon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=515 AND g.name_romanized='Ji Seung-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=515 AND g.name_romanized='Kim Yong-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=515 AND g.name_romanized='Kim Young-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=516 AND g.name_romanized='Kim Dae-myung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=516 AND g.name_romanized='Kim Sang-ho Kwak Do-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=518 AND g.name_romanized='Kim Min-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=519 AND g.name_romanized='Pyo Chang-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=519 AND g.name_romanized='Yoon Seok-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=522 AND g.name_romanized='Ailee Joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=522 AND g.name_romanized='Park Kangnam Yiren';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=525 AND g.name_romanized='Jennie Jisoo Lisa Rosé';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=526 AND g.name_romanized='Im Won-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=526 AND g.name_romanized='Lee Je-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=527 AND g.name_romanized='Choi Yeo-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=527 AND g.name_romanized='Han Ji-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=527 AND g.name_romanized='Lee Joo-bin So Yi-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=529 AND g.name_romanized='Hoshi Mingyu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=529 AND g.name_romanized='Kim Nam-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=529 AND g.name_romanized='Yoon Bo-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=529 AND g.name_romanized='Kim Soo-yong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=529 AND g.name_romanized='Nam Chang-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=532 AND g.name_romanized='Cha Tae-hyun Huening Kai Yeonjun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=533 AND g.name_romanized='Lee Do-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=533 AND g.name_romanized='Lee Jin-wook';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=533 AND g.name_romanized='Lee Si-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=533 AND g.name_romanized='Song';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=533 AND g.name_romanized='Kang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=534 AND g.name_romanized='Kim Kwang-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=534 AND g.name_romanized='Ryu Hyun-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=535 AND g.name_romanized='Lee Yeon-hee Sooyoung Teo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=535 AND g.name_romanized='Yoo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=535 AND g.name_romanized='Yoo Yeon-seok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=539 AND g.name_romanized='Defconn Kim Bo-sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=540 AND g.name_romanized='Cha Chung-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=540 AND g.name_romanized='Kim Jae-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=540 AND g.name_romanized='Shin Dong-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=541 AND g.name_romanized='Ahn Eun-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=541 AND g.name_romanized='Bae Yoon-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=541 AND g.name_romanized='Lee Sang-yi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=542 AND g.name_romanized='Ha Do-kwon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=542 AND g.name_romanized='Park Eun-seok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=542 AND g.name_romanized='Yoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=542 AND g.name_romanized='Jong-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=546 AND g.name_romanized='Jang Dong-yoon Keum Sae-rok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=546 AND g.name_romanized='Kim Dong-jun Park';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=546 AND g.name_romanized='Sung-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=548 AND g.name_romanized='Jessi Wooyoung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=549 AND g.name_romanized='Eunji Minyoung Yujeong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=550 AND g.name_romanized='Choa Jo Se-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=551 AND g.name_romanized='Jung Hye-in';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=551 AND g.name_romanized='Lee Cho-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=551 AND g.name_romanized='Seol In-ah';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=556 AND g.name_romanized='Lee Yong-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=561 AND g.name_romanized='Han Chae-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=561 AND g.name_romanized='Heo Young-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=564 AND g.name_romanized='Chae Jong-hyeop';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=564 AND g.name_romanized='Ha Do-kwon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=565 AND g.name_romanized='Lee Yong-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=567 AND g.name_romanized='Heo Young-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=567 AND g.name_romanized='Lee Young-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=569 AND g.name_romanized='Hani';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=569 AND g.name_romanized='Park Ki-woong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=569 AND g.name_romanized='Yoon Shi-yoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=571 AND g.name_romanized='Lee Mi-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=571 AND g.name_romanized='Lee Sang-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=571 AND g.name_romanized='Lee Young-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=572 AND g.name_romanized='Ahn Hye-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=572 AND g.name_romanized='Kim Hee-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=572 AND g.name_romanized='Kim Yeon-koung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=572 AND g.name_romanized='Lee So-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=572 AND g.name_romanized='Oh Ji-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=573 AND g.name_romanized='jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=573 AND g.name_romanized='Yeum Hye-seon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=576 AND g.name_romanized='Bibi Jeong Jun-ha Luda';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=576 AND g.name_romanized='Yeji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=577 AND g.name_romanized='Kim Jun-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=578 AND g.name_romanized='Jang Hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=579 AND g.name_romanized='Aiki Honey J LEEJUNG MONIKA';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=581 AND g.name_romanized='Arin Jin Ji-hee San';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=584 AND g.name_romanized='Cha Chung-hwa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=584 AND g.name_romanized='Ha Do-kwon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=584 AND g.name_romanized='Heo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=584 AND g.name_romanized='Young-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=588 AND g.name_romanized='Irene';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=588 AND g.name_romanized='Kim';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=588 AND g.name_romanized='Joo Woo-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=588 AND g.name_romanized='Lee Hyun-yi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=588 AND g.name_romanized='Song Hae-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=591 AND g.name_romanized='Park Se-ri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=592 AND g.name_romanized='Joo Woo-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=593 AND g.name_romanized='KCM';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=593 AND g.name_romanized='Parc Jae-jung Wonstein';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=594 AND g.name_romanized='Jo Se-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=595 AND g.name_romanized='Cha Jun-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=595 AND g.name_romanized='Jin Ji-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=597 AND g.name_romanized='Kim Hee-jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=597 AND g.name_romanized='Park Ah-in Roh Jeong-eui';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=601 AND g.name_romanized='Byeon Woo-seok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=601 AND g.name_romanized='Joo Woo-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=601 AND g.name_romanized='Park Kyung-hye';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=602 AND g.name_romanized='Cho Jun-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=602 AND g.name_romanized='Cho Jun-hyun Hyoyeon Yuri';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=604 AND g.name_romanized='Cha Eun-woo Moonbin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=604 AND g.name_romanized='Yoon San-ha';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=605 AND g.name_romanized='Hong Ye-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=605 AND g.name_romanized='Hwang Seok-jeong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=605 AND g.name_romanized='Kim Ji-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=606 AND g.name_romanized='Heo Young-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=606 AND g.name_romanized='Jo Se-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=611 AND g.name_romanized='KCM';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=611 AND g.name_romanized='Mirani Park Cho-rong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=611 AND g.name_romanized='Yoon Bo-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=620 AND g.name_romanized='Choi Yeo-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=620 AND g.name_romanized='Jin Seo-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=620 AND g.name_romanized='Ok Ja-yeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=626 AND g.name_romanized='Manny Pacquiao';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=627 AND g.name_romanized='Jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=629 AND g.name_romanized='Jung Sang-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=629 AND g.name_romanized='Kim Rae-won Park';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=629 AND g.name_romanized='Byung-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=630 AND g.name_romanized='Jo Se-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=630 AND g.name_romanized='Kim Ji-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=635 AND g.name_romanized='Joo Woo-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=637 AND g.name_romanized='Choi Doo-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=637 AND g.name_romanized='Choo Sung-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=637 AND g.name_romanized='Jung Chan-sung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=637 AND g.name_romanized='Kim Dong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=638 AND g.name_romanized='Kim Shin-rok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=639 AND g.name_romanized='An Yu-jin Gaeul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=639 AND g.name_romanized='Jang Won-young Leeseo Liz';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=639 AND g.name_romanized='Rei';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=640 AND g.name_romanized='Donnie Yen';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=640 AND g.name_romanized='Jang Hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=642 AND g.name_romanized='Byul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=642 AND g.name_romanized='Heo Kyung-hwan Seogy';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=643 AND g.name_romanized='Cha Tae-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=643 AND g.name_romanized='Yoo Yeon-seok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=645 AND g.name_romanized='Joo Woo-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=645 AND g.name_romanized='Roh Yoon-seo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=649 AND g.name_romanized='Kang Hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=649 AND g.name_romanized='Shin Ye-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=651 AND g.name_romanized='Manny Pacquiao Ryan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=651 AND g.name_romanized='Bang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=655 AND g.name_romanized='Jo Se-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=655 AND g.name_romanized='Kang Hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=656 AND g.name_romanized='Kim Dong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=658 AND g.name_romanized='DEX';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=658 AND g.name_romanized='Han Ji-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=658 AND g.name_romanized='Lee Se-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=661 AND g.name_romanized='Joohoney';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=661 AND g.name_romanized='Yun Sung-bin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=662 AND g.name_romanized='Dae-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=662 AND g.name_romanized='Lee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=662 AND g.name_romanized='Hwang Kwang-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=669 AND g.name_romanized='Kang Hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=673 AND g.name_romanized='Jung So-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=673 AND g.name_romanized='Kang Ha-neul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=674 AND g.name_romanized='Kim Dong-hwi Yoo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=674 AND g.name_romanized='Seung-ho Yoo Su-bin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=675 AND g.name_romanized='Lee Joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=675 AND g.name_romanized='Uhm Ki-joon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=675 AND g.name_romanized='Yoon Jong-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=676 AND g.name_romanized='Hoshi Seung-kwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=680 AND g.name_romanized='Hong Jin-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=680 AND g.name_romanized='Shin Ye-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=682 AND g.name_romanized='Yoo Seung-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=684 AND g.name_romanized='Joo Hyun-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=684 AND g.name_romanized='Kwon Eun-bi Tsuki';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=685 AND g.name_romanized='Beomgyu Huening Kai Soobin Taehyun Yeonjun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=685 AND g.name_romanized='Kim Dong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=686 AND g.name_romanized='Keum Sae-rok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=686 AND g.name_romanized='Kim Dong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=686 AND g.name_romanized='Noh Sang-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=687 AND g.name_romanized='Eom Ji-yoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=687 AND g.name_romanized='Jo Se-ho Kyuhyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=688 AND g.name_romanized='Hong Jin-ho Jonathan Yiombi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=688 AND g.name_romanized='Kim Dong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=692 AND g.name_romanized='Ahn Bo-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=692 AND g.name_romanized='Park Ji-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=693 AND g.name_romanized='Hong Jin-ho Kazuha';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=693 AND g.name_romanized='Kim Chae-won Sakura';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=693 AND g.name_romanized='Kim Dong-';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=693 AND g.name_romanized='hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=695 AND g.name_romanized='Hong Jin-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=695 AND g.name_romanized='Kim Dong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=697 AND g.name_romanized='Jonathan Yiombi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=697 AND g.name_romanized='Kang Hoon Ma Sun-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=697 AND g.name_romanized='Oh Ha-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=701 AND g.name_romanized='Bae Hye-ji Jonathan Yiombi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=701 AND g.name_romanized='Kang Hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=701 AND g.name_romanized='Kim Dong-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=701 AND g.name_romanized='Ma Sun-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=701 AND g.name_romanized='Seo Eun-kwang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=702 AND g.name_romanized='Joo Jong-hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=702 AND g.name_romanized='Kang Han-na';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=703 AND g.name_romanized='Kwon Eun-bi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=704 AND g.name_romanized='Byeon Woo-seok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=705 AND g.name_romanized='An Yu-jin Rei';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=707 AND g.name_romanized='Ji Ye-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=707 AND g.name_romanized='Park Ju-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=708 AND g.name_romanized='Heo Kyung-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=708 AND g.name_romanized='Hwang Hee-chan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=708 AND g.name_romanized='Jang Hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=708 AND g.name_romanized='Kang Jae-jun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=708 AND g.name_romanized='Oh Ha-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=708 AND g.name_romanized='Zico';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=712 AND g.name_romanized='Nam Ji-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=712 AND g.name_romanized='P.O';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=715 AND g.name_romanized='Park Sung-woong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=715 AND g.name_romanized='Yoon Kyung-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=716 AND g.name_romanized='Kim Ha-yun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=716 AND g.name_romanized='Kim Min-jong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=716 AND g.name_romanized='Oh Sang-uk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=716 AND g.name_romanized='Park Hye-jeong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=716 AND g.name_romanized='Park Sang-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=717 AND g.name_romanized='Joo Hyun-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=717 AND g.name_romanized='Kim Ah-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=719 AND g.name_romanized='Hong Jin-ho Keum Sae-rok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=720 AND g.name_romanized='Jonathan Yiombi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=720 AND g.name_romanized='Kwon Eun-bi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=721 AND g.name_romanized='Haewon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=721 AND g.name_romanized='Kim Dong-jun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=724 AND g.name_romanized='Lee Yoo-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=724 AND g.name_romanized='Woo Do-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=725 AND g.name_romanized='Kim Ah-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=725 AND g.name_romanized='Lee Min-hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=725 AND g.name_romanized='Seo Eun-kwang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=726 AND g.name_romanized='Hong Kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=726 AND g.name_romanized='Kim Min-ju Roh Yoon-seo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=727 AND g.name_romanized='Kim Dong-jun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=727 AND g.name_romanized='Rami Rora';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=728 AND g.name_romanized='Joo Hyun-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=730 AND g.name_romanized='Dahyun Kyuhyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=734 AND g.name_romanized='Kang Hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=736 AND g.name_romanized='Choi Daniel';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=736 AND g.name_romanized='Jeon So-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=736 AND g.name_romanized='Kim Ha-yun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=736 AND g.name_romanized='Park Hye-jeong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=738 AND g.name_romanized='Kyuhyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=738 AND g.name_romanized='Lee Seok-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=738 AND g.name_romanized='Park Eun-tae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=740 AND g.name_romanized='Joo Jong-hyuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=740 AND g.name_romanized='Kim Si-eun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=742 AND g.name_romanized='Choi Daniel';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=742 AND g.name_romanized='Kim Ah-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=743 AND g.name_romanized='Heo Kyung-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=743 AND g.name_romanized='Jang Seong-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=743 AND g.name_romanized='Park Ji-won';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=745 AND g.name_romanized='Hong Eun-chae Sakura';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=746 AND g.name_romanized='Kim Ji-yeon Yook Sung-jae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=750 AND g.name_romanized='Kai Kim Ah-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=752 AND g.name_romanized='Son Ho-jun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=752 AND g.name_romanized='Yoo Seung-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=753 AND g.name_romanized='Miyeon Soyeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=754 AND g.name_romanized='Lee Seung-hyub';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=754 AND g.name_romanized='Park Ji-hu';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=756 AND g.name_romanized='Kim Ah-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=759 AND g.name_romanized='Ahyeon Asa';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=759 AND g.name_romanized='Joo Hyun-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=760 AND g.name_romanized='Eunhyuk Kyuhyun Leeteuk';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=761 AND g.name_romanized='Miyeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=762 AND g.name_romanized='Kim Ha-neul';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=762 AND g.name_romanized='Lee Jun-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=762 AND g.name_romanized='Nam Woo-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=765 AND g.name_romanized='Seo Jang-hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=765 AND g.name_romanized='Shin Gi-ru Shindong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=766 AND g.name_romanized='Kim Ha-yun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=766 AND g.name_romanized='Kim Min-jong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=767 AND g.name_romanized='Jang Dong-yoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=767 AND g.name_romanized='Kim Ah-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=770 AND g.name_romanized='Cha Tae-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=771 AND g.name_romanized='Jooheon Kwon Eun-bi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=772 AND g.name_romanized='Jun Hyun-moo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=772 AND g.name_romanized='Jung Seung-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=773 AND g.name_romanized='Bang Hyo-rin Byun Yo-han';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=773 AND g.name_romanized='Kim Kang-woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=773 AND g.name_romanized='Yang Se-jong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=774 AND g.name_romanized='Jeon So-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=774 AND g.name_romanized='Yang Se-hyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=775 AND g.name_romanized='Jonathan Yiombi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=777 AND g.name_romanized='Kim Byung-chul Miyeon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=777 AND g.name_romanized='Sunmi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=778 AND g.name_romanized='Ahn Eun-jin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=778 AND g.name_romanized='Kim Mu-jun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=779 AND g.name_romanized='Heo Kyung-hwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=781 AND g.name_romanized='Kang Hoon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=785 AND g.name_romanized='Jung Eun-ji';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=785 AND g.name_romanized='Kim Nam-joo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=785 AND g.name_romanized='Oh Ha-young';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=785 AND g.name_romanized='Park Cho-rong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=785 AND g.name_romanized='Yoon Bo-mi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=786 AND g.name_romanized='Kwon Eun-bi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=787 AND g.name_romanized='Kim Hye-yoon Lomon';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=789 AND g.name_romanized='Hong Jin-ho Mimi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=790 AND g.name_romanized='Kyuhyun Roy Kim';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=794 AND g.name_romanized='Choi Min-jeong';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=794 AND g.name_romanized='Lee Jeong-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=794 AND g.name_romanized='Lee June-seo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=794 AND g.name_romanized='Noh Do-hee';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=794 AND g.name_romanized='Shin Dong-min';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=795 AND g.name_romanized='Park Shin-yang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=795 AND g.name_romanized='Lee Chang-sub Sung Si-kyung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=797 AND g.name_romanized='Jung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=797 AND g.name_romanized='Woo';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=797 AND g.name_romanized='Shin Seung-ho';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=798 AND g.name_romanized='Hwasa Young K';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=799 AND g.name_romanized='Park Eun-tae';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=799 AND g.name_romanized='Shin Sung-rok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=799 AND g.name_romanized='Yoo Jun-sang';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=801 AND g.name_romanized='Chung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=801 AND g.name_romanized='Jeon Somi';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=802 AND g.name_romanized='Chae Won-bin';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=802 AND g.name_romanized='Kim Min-seok';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=802 AND g.name_romanized='Yoo Hee-kwan';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=803 AND g.name_romanized='Gong Myung';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=804 AND g.name_romanized='Ahn Jae-hyun';
INSERT IGNORE INTO episode_guests (episode_id, guest_id) SELECT e.episode_id, g.guest_id FROM episodes e, guests g WHERE e.episode_number=804 AND g.name_romanized='Kang So-ra';

-- Step 4: Verify
SELECT COUNT(DISTINCT episode_id) AS episodes_with_guests,
       COUNT(*) AS total_links,
       (SELECT COUNT(*) FROM guests) AS unique_guests
FROM episode_guests;

-- Spot-check the originally-buggy episode
SELECT e.episode_number, g.name_romanized
FROM episodes e
JOIN episode_guests eg ON eg.episode_id = e.episode_id
JOIN guests g ON g.guest_id = eg.guest_id
WHERE e.episode_number = 804;