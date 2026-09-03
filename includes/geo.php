<?php
/**
 * India geo seed helpers (countries / states / cities cascading).
 */

function ensure_geo_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS countries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(10) NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_country_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS states (
            id INT AUTO_INCREMENT PRIMARY KEY,
            country_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_state_country (country_id, name),
            KEY idx_states_country (country_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            state_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_city_state (state_id, name),
            KEY idx_cities_state (state_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // ignore
    }
    $done = true;
}

/** Full India states/UTs + major cities (district HQs / important cities). */
function india_geo_seed_map(): array
{
    return [
        'Andhra Pradesh' => ['Visakhapatnam','Vijayawada','Guntur','Nellore','Kurnool','Rajahmundry','Tirupati','Kadapa','Anantapur','Eluru','Ongole','Chittoor','Srikakulam','Vizianagaram','Machilipatnam','Tenali','Proddatur','Hindupur','Bhimavaram','Madanapalle','Gudivada','Nandyal','Tadepalligudem','Chilakaluripet'],
        'Arunachal Pradesh' => ['Itanagar','Naharlagun','Pasighat','Tawang','Ziro','Bomdila','Tezu','Aalo','Changlang','Khonsa','Roing','Namsai','Seppa','Daporijo'],
        'Assam' => ['Guwahati','Silchar','Dibrugarh','Jorhat','Nagaon','Tinsukia','Tezpur','Bongaigaon','Dhubri','Sivasagar','Goalpara','Barpeta','Karimganj','Lakhimpur','Diphu','Haflong','Golaghat','Nalbari','Mangaldoi','Kokrajhar'],
        'Bihar' => ['Patna','Gaya','Bhagalpur','Muzaffarpur','Purnia','Darbhanga','Bihar Sharif','Arrah','Begusarai','Katihar','Munger','Chhapra','Danapur','Saharsa','Sasaram','Hajipur','Dehri','Siwan','Motihari','Nawada','Bagra','Bettiah','Samastipur','Jehanabad','Aurangabad','Buxar','Madhubani','Sitamarhi','Supaul','Madhepura'],
        'Chhattisgarh' => ['Raipur','Bhilai','Bilaspur','Korba','Durg','Rajnandgaon','Raigarh','Jagdalpur','Ambikapur','Dhamtari','Mahasamund','Kanker','Kawardha','Janjgir','Bemetara','Balod','Kondagaon','Surajpur'],
        'Goa' => ['Panaji','Margao','Vasco da Gama','Mapusa','Ponda','Bicholim','Curchorem','Canacona','Valpoi','Sanquelim'],
        'Gujarat' => ['Ahmedabad','Surat','Vadodara','Rajkot','Bhavnagar','Jamnagar','Junagadh','Gandhinagar','Anand','Nadiad','Morbi','Mehsana','Bharuch','Vapi','Navsari','Surendranagar','Gandhidham','Veraval','Porbandar','Godhra','Palanpur','Bhuj','Patan','Dahod','Valsad','Amreli','Botad','Himatnagar','Modasa','Kalol'],
        'Haryana' => ['Faridabad','Gurugram','Panipat','Ambala','Yamunanagar','Rohtak','Hisar','Karnal','Sonipat','Panchkula','Bhiwani','Sirsa','Bahadurgarh','Jind','Thanesar','Kaithal','Rewari','Palwal','Fatehabad','Tohana','Narwana','Mandi Dabwali','Charkhi Dadri','Narnaul','Mahendragarh'],
        'Himachal Pradesh' => ['Shimla','Dharamshala','Solan','Mandi','Palampur','Baddi','Nahan','Kullu','Hamirpur','Una','Bilaspur','Chamba','Kangra','Sundernagar','Nalagarh','Paonta Sahib','Manali','Reckong Peo','Keylong'],
        'Jharkhand' => ['Ranchi','Jamshedpur','Dhanbad','Bokaro','Deoghar','Hazaribagh','Giridih','Ramgarh','Phusro','Medininagar','Chaibasa','Dumka','Sahibganj','Gumla','Lohardaga','Chatra','Pakur','Godda','Jhumri Telaiya','Simdega'],
        'Karnataka' => ['Bengaluru','Mysuru','Hubballi','Mangaluru','Belagavi','Kalaburagi','Davanagere','Ballari','Vijayapura','Shivamogga','Tumakuru','Raichur','Bidar','Hosapete','Gadag','Udupi','Robertsonpet','Bhadravati','Chitradurga','Kolar','Mandya','Hassan','Chikkamagaluru','Bagalkot','Ranebennuru','Gangavati','Yadgir','Karwar','Sirsi','Chikkaballapur'],
        'Kerala' => ['Thiruvananthapuram','Kochi','Kozhikode','Thrissur','Kollam','Kannur','Alappuzha','Kottayam','Palakkad','Malappuram','Manjeri','Thalassery','Ponnani','Vatakara','Kanhangad','Kasargod','Pathanamthitta','Idukki','Punalur','Neyyattinkara','Kayamkulam','Changanassery','Tirur','Perinthalmanna','Ottapalam'],
        'Madhya Pradesh' => ['Indore','Bhopal','Jabalpur','Gwalior','Ujjain','Sagar','Dewas','Satna','Ratlam','Rewa','Murwara','Singrauli','Burhanpur','Khandwa','Bhind','Chhindwara','Guna','Shivpuri','Vidisha','Chhatarpur','Damoh','Mandsaur','Khargone','Neemuch','Pithampur','Hoshangabad','Itarsi','Sehore','Betul','Seoni','Morena','Datia','Tikamgarh','Shahdol','Mandla'],
        'Maharashtra' => ['Mumbai','Pune','Nagpur','Thane','Nashik','Aurangabad','Solapur','Amravati','Navi Mumbai','Kolhapur','Nanded','Sangli','Jalgaon','Akola','Latur','Dhule','Ahmednagar','Chandrapur','Parbhani','Ichalkaranji','Jalna','Bhusawal','Panvel','Satara','Beed','Yavatmal','Kamptee','Gondia','Barshi','Achalpur','Osmanabad','Nandurbar','Wardha','Udgir','Hinganghat','Malegaon','Bhiwandi','Ulhasnagar','Kalyan','Mira-Bhayandar','Vasai-Virar','Ambarnath','Badlapur','Ratnagiri','Sindhudurg','Palghar','Raigad','Alibag'],
        'Manipur' => ['Imphal','Thoubal','Bishnupur','Churachandpur','Ukhrul','Kakching','Senapati','Tamenglong','Jiribam','Moreh','Kangpokpi','Noney'],
        'Meghalaya' => ['Shillong','Tura','Nongstoin','Jowai','Baghmara','Williamnagar','Resubelpara','Mairang','Nongpoh','Khliehriat'],
        'Mizoram' => ['Aizawl','Lunglei','Champhai','Serchhip','Kolasib','Lawngtlai','Saiha','Mamit','Hnahthial','Khawzawl','Saitual'],
        'Nagaland' => ['Kohima','Dimapur','Mokokchung','Tuensang','Wokha','Zunheboto','Mon','Phek','Kiphire','Longleng','Peren','Chumukedima'],
        'Odisha' => ['Bhubaneswar','Cuttack','Rourkela','Berhampur','Sambalpur','Puri','Baleshwar','Bhadrak','Baripada','Balangir','Jharsuguda','Bargarh','Rayagada','Jeypore','Bhawanipatna','Dhenkanal','Kendujhar','Paradip','Sunabeda','Angul','Kendrapara','Jagatsinghpur','Nabarangpur','Koraput','Malkangiri','Phulbani','Sundargarh'],
        'Punjab' => ['Ludhiana','Amritsar','Jalandhar','Patiala','Bathinda','Mohali','Hoshiarpur','Batala','Pathankot','Moga','Abohar','Malerkotla','Khanna','Phagwara','Muktsar','Barnala','Rajpura','Firozpur','Kapurthala','Faridkot','Sunam','Sangrur','Gurdaspur','Nabha','Tarn Taran','Mansa','Malout','Fazilka','Rupnagar','Nawanshahr'],
        'Rajasthan' => ['Jaipur','Jodhpur','Kota','Bikaner','Ajmer','Udaipur','Bhilwara','Alwar','Bharatpur','Sikar','Pali','Sri Ganganagar','Tonk','Kishangarh','Beawar','Hanumangarh','Dhaulpur','Gangapur City','Sawai Madhopur','Churu','Baran','Chittorgarh','Makrana','Nagaur','Hindaun','Banswara','Dungarpur','Bundi','Jhunjhunu','Sujangarh','Jhalawar','Barmer','Jaisalmer','Sirohi','Rajsamand','Karauli','Pratapgarh','Dausa'],
        'Sikkim' => ['Gangtok','Namchi','Gyalshing','Mangan','Rangpo','Jorethang','Singtam','Pakyong','Ravangla'],
        'Tamil Nadu' => ['Chennai','Coimbatore','Madurai','Tiruchirappalli','Salem','Tirunelveli','Tiruppur','Erode','Vellore','Thoothukudi','Dindigul','Thanjavur','Ranipet','Sivakasi','Karur','Nagercoil','Kanchipuram','Cuddalore','Tiruvannamalai','Pollachi','Rajapalayam','Gudiyatham','Pudukkottai','Vaniyambadi','Ambur','Nagapattinam','Hosur','Karaikudi','Neyveli','Kumbakonam','Mayiladuthurai','Theni','Dharmapuri','Krishnagiri','Namakkal','Virudhunagar','Ramanathapuram','Ariyalur','Perambalur','Nilgiris','Udhagamandalam'],
        'Telangana' => ['Hyderabad','Warangal','Nizamabad','Khammam','Karimnagar','Ramagundam','Mahbubnagar','Nalgonda','Adilabad','Suryapet','Miryalaguda','Siddipet','Jagtial','Mancherial','Nirmal','Kamareddy','Kothagudem','Bodhan','Sangareddy','Zaheerabad','Wanaparthy','Gadwal','Medak','Vikarabad','Jangaon','Bhuvanagiri'],
        'Tripura' => ['Agartala','Udaipur','Dharmanagar','Kailashahar','Belonia','Ambassa','Khowai','Teliamura','Sabroom','Sonamura','Bishalgarh'],
        'Uttar Pradesh' => ['Lucknow','Kanpur','Ghaziabad','Agra','Meerut','Varanasi','Prayagraj','Bareilly','Aligarh','Moradabad','Saharanpur','Gorakhpur','Noida','Firozabad','Jhansi','Muzaffarnagar','Mathura','Ayodhya','Shahjahanpur','Rampur','Mau','Hapur','Etawah','Mirzapur','Bulandshahr','Sambhal','Amroha','Hardoi','Fatehpur','Raebareli','Orai','Sitapur','Bahraich','Modinagar','Unnao','Jaunpur','Lakhimpur','Hathras','Banda','Pilibhit','Barabanki','Khurja','Gonda','Mainpuri','Lalitpur','Etah','Deoria','Budaun','Ghazipur','Sultanpur','Azamgarh','Bijnor','Basti','Chandausi','Akbarpur','Ballia','Tanda','Greater Noida','Shikohabad','Shamli','Awagarh','Kasganj','Kannauj','Padrauna','Khatauli','Balrampur','Najibabad','Nagina','Sikandrabad','Chandpur','Robertsganj','Mahoba','Rath','Chitrakoot'],
        'Uttarakhand' => ['Dehradun','Haridwar','Roorkee','Haldwani','Rudrapur','Kashipur','Rishikesh','Kotdwar','Ramnagar','Pithoragarh','Mussoorie','Nainital','Almora','Srinagar','Tehri','Pauri','Champawat','Bageshwar','Uttarkashi','Joshimath','Gopeshwar'],
        'West Bengal' => ['Kolkata','Howrah','Durgapur','Asansol','Siliguri','Bardhaman','Malda','Baharampur','Habra','Kharagpur','Shantipur','Dankuni','Dhulian','Ranaghat','Haldia','Raiganj','Krishnanagar','Nabadwip','Medinipur','Jalpaiguri','Balurghat','Bankura','Purulia','Cooch Behar','Alipurduar','Chandannagar','Bishnupur','Bolpur','Kalyani','Basirhat','Bangaon','English Bazar','Contai','Tamluk','Jhargram'],
        'Andaman and Nicobar Islands' => ['Port Blair','Diglipur','Mayabunder','Rangat','Car Nicobar','Hut Bay','Campbell Bay'],
        'Chandigarh' => ['Chandigarh','Manimajra','Sector 17','Sector 22','Sector 35'],
        'Dadra and Nagar Haveli and Daman and Diu' => ['Daman','Diu','Silvassa','Amli','Naroli','Khanvel'],
        'Delhi' => ['New Delhi','Central Delhi','North Delhi','South Delhi','East Delhi','West Delhi','North East Delhi','North West Delhi','South East Delhi','South West Delhi','Shahdara','Dwarka','Rohini','Karol Bagh','Saket','Laxmi Nagar','Janakpuri','Connaught Place','Okhla','Vasant Kunj','Pitampura','Mayur Vihar'],
        'Jammu and Kashmir' => ['Srinagar','Jammu','Anantnag','Baramulla','Sopore','Kathua','Udhampur','Sopore','Punch','Rajouri','Kupwara','Bandipora','Ganderbal','Pulwama','Shopian','Kulgam','Samba','Reasi','Ramban','Doda','Kishtwar','Budgam'],
        'Ladakh' => ['Leh','Kargil','Diskit','Nubra','Padum','Drass','Nyoma'],
        'Lakshadweep' => ['Kavaratti','Agatti','Amini','Andrott','Minicoy','Kadmat','Kalpeni','Kiltan'],
        'Puducherry' => ['Puducherry','Karaikal','Mahe','Yanam','Oulgaret','Villianur'],
    ];
}

/**
 * Ensure India + all states/UTs + major cities exist.
 * Safe to call repeatedly (INSERT IGNORE by unique name keys).
 */
function ensure_india_geo_seed(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ensure_geo_tables($pdo);

    try {
        $pdo->prepare("INSERT IGNORE INTO countries (name, code, status) VALUES ('India', 'IN', 'active')")->execute();
        // Also ensure id=1 India if empty install used fixed ids
        $indiaId = (int) $pdo->query("SELECT id FROM countries WHERE name = 'India' LIMIT 1")->fetchColumn();
        if ($indiaId <= 0) {
            $done = true;
            return;
        }
        $pdo->prepare("UPDATE countries SET status = 'active', code = COALESCE(NULLIF(code,''), 'IN') WHERE id = ?")->execute([$indiaId]);

        $map = india_geo_seed_map();
        $insState = $pdo->prepare("INSERT IGNORE INTO states (country_id, name, status) VALUES (?, ?, 'active')");
        foreach (array_keys($map) as $stateName) {
            $insState->execute([$indiaId, $stateName]);
        }
        // Activate any existing India states that match
        $pdo->prepare("UPDATE states SET status = 'active' WHERE country_id = ?")->execute([$indiaId]);

        // Remap legacy short names
        $aliases = [
            'Dadra and Nagar Haveli' => 'Dadra and Nagar Haveli and Daman and Diu',
            'Daman and Diu' => 'Dadra and Nagar Haveli and Daman and Diu',
            'Orissa' => 'Odisha',
            'Pondicherry' => 'Puducherry',
            'NCT of Delhi' => 'Delhi',
        ];
        foreach ($aliases as $old => $new) {
            try {
                $pdo->prepare('UPDATE IGNORE states SET name = ? WHERE country_id = ? AND name = ?')
                    ->execute([$new, $indiaId, $old]);
            } catch (Throwable $e) {
            }
        }

        $stateRows = $pdo->prepare('SELECT id, name FROM states WHERE country_id = ?');
        $stateRows->execute([$indiaId]);
        $stateIds = [];
        foreach ($stateRows->fetchAll() as $row) {
            $stateIds[$row['name']] = (int) $row['id'];
        }

        $insCity = $pdo->prepare("INSERT IGNORE INTO cities (state_id, name, status) VALUES (?, ?, 'active')");
        foreach ($map as $stateName => $cities) {
            $sid = $stateIds[$stateName] ?? 0;
            if ($sid <= 0) {
                continue;
            }
            $unique = [];
            foreach ($cities as $city) {
                $city = trim($city);
                if ($city === '' || isset($unique[$city])) {
                    continue;
                }
                $unique[$city] = true;
                $insCity->execute([$sid, $city]);
            }
            $pdo->prepare("UPDATE cities SET status = 'active' WHERE state_id = ?")->execute([$sid]);
        }
    } catch (Throwable $e) {
        // ignore seed failures
    }

    $done = true;
}
