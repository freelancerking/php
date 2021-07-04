<?php
    require_once 'lib.php';
    require_once 'config.php';
    require_once 'PhpExcel/Classes/PHPExcel.php';
    require_once 'PhpExcel/Classes/PHPExcel/IOFactory.php';

	function errHandle($errNo, $errStr, $errFile, $errLine) {
		$msg = "$errStr in $errFile on line $errLine";
		if ($errNo == E_NOTICE || $errNo == E_WARNING) {
			throw new ErrorException($msg, $errNo);
		} else {
			echo $msg;
		}
	}

    function itemExists($mysqli, $itemurl) 
    {
        $query = "SELECT count(id) FROM `items` WHERE url = '$itemurl'";
        $result = $mysqli->query($query);

        $row = $result->fetch_row();
	return $row[0];
    }

    function printmessage($msg)
    {
        echo date("Y-m-d H:i:s").": ".$msg.'<br>'.PHP_EOL;
    }
	
    function logmessage($fh, $msg)
    {
        fputs($fh, $msg.'<br>'.PHP_EOL);
    }	

	set_error_handler('errHandle');
    //if (!file_exists("images"))
    //    mkdir("images");
	error_reporting(E_ALL ^ E_DEPRECATED);
	chdir(__DIR__);
    printmessage("Start scrape");
	$fh = fopen("marktplaatsdaily.log", "w");
	logmessage($fh, "Start scrape: " . date("Y-m-d H:i:s"));

    $conn = new mysqli($dbhost,$dbuser,$dbpassword,'test');
    // Check connection
    if ($conn -> connect_errno) 
    {
        echo "Failed to connect to MySQL: " . $conn -> connect_error;
        exit();
    }

	$itemscount = 0;
	$imagescount = 0;
	$errorcount = 0;
	$proxies = readproxies("proxies.txt");
	
    $curdate = gmdate("Y-m-d");   // GMT
    $ch = initcurl();
    $url = "https://www.marktplaats.nl/l/antiek-en-kunst/#sortBy:SORT_INDEX|sortOrder:DECREASING";
    $category = "marktplaats";

    $html = curlget($ch, $url);
    $html = preg_replace('/[^\S ]+/', '', $html);
    $numberoflots = getbyregex($html, '/"totalResultCount":(?P<data>.*?),/ism', "data");
    $pages = intval(intval($numberoflots) / 30 + 1);
    //if (!file_exists($category))
    //    mkdir($category, 0, true);
    $imagesfolder = "/mnt/imgcomp/data/test/$category/images";
    if (!file_exists($imagesfolder))
        mkdir($imagesfolder);
    
    //echo $numberoflots . "<br>" . PHP_EOL;
    //echo $pages . "<br>" . PHP_EOL;

    $offset = 0;
    $exitloop = 0;
    while ($exitloop == 0)
    {
		$tries = 1;
		do
		{
			$proxy = getrandomproxy($proxies);
			$tmp = explode(":", $proxy);
			echo $proxy . "<br>" . PHP_EOL;
			//$proxy = "45.9.123.37:19999";	//rotating proxy
			curl_setopt($ch, CURLOPT_PROXY, $tmp[0].":".$tmp[1]);
			curl_setopt($ch, CURLOPT_PROXYUSERPWD, $tmp[2].":".$tmp[3]);
			$json = curlget($ch, "https://www.marktplaats.nl/lrp/api/search?l1CategoryId=1&limit=30&offset=$offset&searchInTitleAndDescription=true&sortBy=SORT_INDEX&sortOrder=DECREASING&viewOptions=list-view");
			file_put_contents("json/$offset.json", $json);
			$data = json_decode($json, true);
			++$tries;
			if ($data == null)
				sleep(5);
		}
		while ($data == null && $tries < 5);
		
        if ($data == null)
        {
            echo "json error, offset $offset <br>" . PHP_EOL;
			echo $json . "<br>" . PHP_EOL;
            exit();
        }

        for ($j = 0; $j < count($data['listings']); ++$j)
        {
            $deturl = "https://www.marktplaats.nl/".$data['listings'][$j]['vipUrl'];
            $date   = $data['listings'][$j]['date'];                
            $showwebsiteurl = $data['listings'][$j]['sellerInformation']['showWebsiteUrl'];               
            $sellername = $data['listings'][$j]['sellerInformation']['sellerName'];               
            $price = $data['listings'][$j]['priceInfo']['priceCents'];               
            if ($price > 0)
                $price = $price / 100;
            $title   = $data['listings'][$j]['title'];
            $lotid   = $data['listings'][$j]['itemId'];

            //echo $curdate . '<br>' . PHP_EOL;
            //echo $date . '<br>' . PHP_EOL;

            if (substr($date, 0, 10) == $curdate)
            {
                if (!$showwebsiteurl && itemExists($conn, $deturl) == 0)
                {
                    $dethtml = curlget($ch, $deturl);
                    $dethtml = preg_replace('/[^\S ]+/', '', $dethtml);
                    $dethtml = preg_replace('/\s+/', ' ', $dethtml);
                    //file_put_contents("detail.html", $dethtml);
                    $description = getbyregex($dethtml, '/<div id="vip\-ad\-description" class="wrapped">(?P<data>.*?)<\/div>/ism', "data");

                    printmessage($category.",".$offset . ":$title saved");
    
                    $maximages = 10;
                    $dataimages = getbyregex($dethtml, '/<div id="vip\-carousel" class="carousel" data\-images\-s="(?P<data>.*?)"/ism', "data");
                    //printmessage($dataimages . " <br>" . PHP_EOL);
                    //exit();

                    $imagesource = explode("&", $dataimages);
                    //print_r($imagesource);
                    if (count($imagesource) > $maximages)
                        $maximages = 10;
                    else
                        $maximages = count($imagesource);
		            $images = array();
                    for ($tt = 0; $tt < $maximages; ++$tt)
                    {
                        $imageurl = "https:" . str_replace('$_14', '$_85', $imagesource[$tt]);
                        $imagesource[$tt] = $imageurl;
                        printmessage("Downloading image $imageurl");
			            $images[] = "$imagesfolder/".$lotid."_".$tt."_".date("Ymd").".jpg";
                        downloadImage($imageurl, "$imagesfolder/".$lotid."_".$tt."_".date("Ymd").".jpg");
						$imagescount++;
                    }
                    $imagesource = array_pad($imagesource, 10, "");
					$images = array_pad($images, 10, "");
					
					$title = mysqli_real_escape_string($conn, $title);
					$description = mysqli_real_escape_string($conn, $description);
					$sellername = mysqli_real_escape_string($conn, $sellername);

                    $sql = "insert into items(category, lotid, url, title, sellername, currentbid, description, lotdetail, image1source, image2source, image3source, image4source, image5source, image6source, image7source, image8source, image9source, image10source, image1, image2, image3, image4, image5, image6, image7, image8, image9, image10) " . 
                        "values ('$category', '$lotid', '$deturl', '$title', '$sellername', '$price', '$description', '', '$imagesource[0]', '$imagesource[1]', '$imagesource[2]', '$imagesource[3]', '$imagesource[4]', '$imagesource[5]', '$imagesource[6]', '$imagesource[7]', '$imagesource[8]', '$imagesource[9]', '$images[0]', '$images[1]', '$images[2]', '$images[3]', '$images[4]', '$images[5]', '$images[6]', '$images[7]', '$images[8]', '$images[9]')";
					if(mysqli_query($conn, $sql))
					{
						//echo "Records inserted successfully.";
						$itemscount++;
					} 
					else
					{
						$errmsg = mysqli_error($conn);
						if (!contains('Duplicate entry', $errmsg))
						{
							echo "ERROR: Could not able to execute $sql. " . $errmsg;
							$errorcount++;
						}
					}                    
                    sleep(5);
                }   // if (itemExists($conn, $deturl) == 0)
                else
                    printmessage($offset . ":$deturl skipped, already exists in db");
            }
            else
            {
                $exitloop = 1;
                break;
            }
        }   // for ($j = 0; $j < count($data['results']); ++$j)
        $offset += 30;
        //$html = curlget($ch, $url[$i] . "p/$page/#Language:nl-BE");
        //$html = preg_replace('/[^\S ]+/', '', $html);
    }        

    printmessage("End scrape");
	logmessage($fh, "End scrape: " . date("Y-m-d H:i:s"));	
	logmessage($fh, "Items scraped: " . $itemscount);	
	logmessage($fh, "Images scraped: " . $imagescount);	
	logmessage($fh, "Errors: $errorcount");	
	fclose($fh);	
?>