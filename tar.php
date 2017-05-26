<?php
class TAR {
    
    
    public static function create_from_file($filename_tar, $filen_out, $filen_in) {
        $f = fopen($filename_tar, 'w+');
        self::TarAddHeader($f, $filen_out, $filen_in);
        self::TarWriteContents($f, $filen_out);
        self::TarAddFooter($f);
        fclose($f);
    }
    
    /**
     * Adds file header to the tar file, it is used before adding file content.
     * @var resource $f: file resource (provided by eg. fopen)
     * @var string $phisfn: path to file
     * @var string $archfn: path to file in archive, directory names must be followed by '/'
     */
    
    private static function TarAddHeader($f, $phisfn, $archfn) {
        $info = stat($phisfn);
        $ouid = sprintf("%6s ", decoct($info[4]));
        $ogid = sprintf("%6s ", decoct($info[5]));
        $omode = sprintf("%6s ", decoct(fileperms($phisfn)));
        $omtime = sprintf("%11s", decoct(filemtime($phisfn)));
        if (is_dir($phisfn)) {
            $type = "5";
            $osize = sprintf("%11s ", decoct(0));
        } else {
            $type = '';
            $osize = sprintf("%11s ", decoct(filesize($phisfn)));
            clearstatcache();
        }
        $dmajor = $dminor = $gname = $linkname = $magic = $prefix = $uname = $version = '';
        $chunkbeforeCS = pack("a100a8a8a8a12A12", $archfn, $omode, $ouid, $ogid, $osize, $omtime);
        $chunkafterCS = pack("a1a100a6a2a32a32a8a8a155a12", $type, $linkname, $magic, $version, $uname, $gname, $dmajor, $dminor, $prefix, '');
        
        $checksum = 0;
        for ($i = 0; $i < 148; $i++)
            $checksum += ord(substr($chunkbeforeCS, $i, 1));
        for ($i = 148; $i < 156; $i++)
            $checksum += ord(' ');
        for ($i = 156, $j = 0; $i < 512; $i++, $j++)
            $checksum += ord(substr($chunkafterCS, $j, 1));
        
        fwrite($f, $chunkbeforeCS, 148);
        $checksum = sprintf("%6s ", decoct($checksum));
        $bdchecksum = pack("a8", $checksum);
        fwrite($f, $bdchecksum, 8);
        fwrite($f, $chunkafterCS, 356);
        return true;
    }
    
    /**
     * Writes file content to the tar file must be called after a TarAddHeader
     * @var resource $f :file resource provided by fopen
     * @var string $phisfn: path to file
     */
    
    private static function TarWriteContents($f, $phisfn) {
        if (is_dir($phisfn))
            return;
        $size = filesize($phisfn);
        $padding = $size % 512 ? 512 - $size % 512 : 0;
        $f2 = fopen($phisfn, "rb");
        while (!feof($f2))
            fwrite($f, fread($f2, 1024 * 1024));
        $pstr = sprintf("a%d", $padding);
        fwrite($f, pack($pstr, ''));
        
    }
    
    /**
     * Adds 1024 byte footer at the end of the tar file
     * @var resource $f: file resource
     */
    
    private static function TarAddFooter($f) {
        fwrite($f, pack('a1024', ''));
    }
    
    protected static function tar_compress($name_tar, $type = "gz", $index = 9) {
        $temp = dirname($name_tar) . DIRECTORY_SEPARATOR . "temp" . microtime(true) . ".tar";
        $temp_dir = dirname($name_tar) . DIRECTORY_SEPARATOR . "temp";
        switch ($type) {
            case 'gz':
                $name = $name_tar . '.gz';
                if (!file_exists($name))
                    return file_put_contents($name, gzencode(file_get_contents($name_tar), $index, FORCE_GZIP)) && unlink($name_tar);
                self::gz_decompress($name, $temp);
                self::overvrite_comp($temp, $temp_dir, $name_tar);
                return file_put_contents($name, gzencode(file_get_contents($name_tar), $index, FORCE_GZIP)) && unlink($name_tar);
                break;
            case 'bz2':
                $name = $name_tar . '.bz2';
                if (!file_exists($name))
                    return file_put_contents($name_tar . '.bz2', bzcompress(file_get_contents($name_tar), $index)) && unlink($name_tar);
                self::bz2_decompress($name, $temp);
                self::overvrite_comp($temp, $temp_dir, $name_tar);
                return file_put_contents($name_tar . '.bz2', bzcompress(file_get_contents($name_tar), $index)) && unlink($name_tar);
                break;
            case 'deflate':
                $name = $name_tar . '.gz';
                if (!file_exists($name))
                    return file_put_contents($name_tar . '.gz', gzencode(file_get_contents($name_tar), $index, FORCE_DEFLATE)) && unlink($name_tar);
                self::gz_decompress($name, $temp);
                self::overvrite_comp($temp, $temp_dir, $name_tar);
                return file_put_contents($name_tar . '.gz', gzencode(file_get_contents($name_tar), $index, FORCE_DEFLATE)) && unlink($name_tar);
                break;
        }
        return false;
    }
    
    protected static function overvrite_comp($temp, $temp_dir, $name_tar) {
        self::extract_tar($temp, $temp_dir, true);
        if (file_exists($temp))
            unlink($temp);
        self::extract_tar($name_tar, $temp_dir, true);
        if (file_exists($name_tar))
            unlink($name_tar);
        self::create_tar($name_tar, $temp_dir);
        self::delete_temp($temp_dir);
    }
    
    protected static function gz_size($file_gz) {
        $file = fopen($file_gz, "rb");
        fseek($file, -4, SEEK_END);
        $buf = fread($file, 4);
        $size = unpack("V", $buf);
        $size = end($size);
        fclose($file);
        return $size;
    }
    
    protected static function gz_decompress($name, $temp) {
        $fh = gzopen($name, "r");
        $contents = gzread($fh, self::gz_size($name));
        gzclose($fh);
        return file_put_contents($temp, $contents);
    }
    
    protected static function bz2_decompress($name, $temp) {
        $decomp_file = '';
        $fh = bzopen($name, 'r');
        do {
            $decomp_file .= $buffer = bzread($fh, 4096);
            if ($buffer === FALSE)
                $sp = true;
            if (bzerror($fh) !== 0)
                $sp = true;
            $sp = feof($fh);
        } while (!$sp);
        bzclose($fh);
        file_put_contents($temp, $decomp_file);
    }
    protected static function create_tar($tar, $dir) {
		try {
			$phar = new PharData($tar);
			$phar->buildFromDirectory($dir);
		}
		catch (Exception $e) {
			return $e->getMessage();
		}
		return true;
	}
    
}
?>
