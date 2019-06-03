<?php
namespace WebPConvert\Serve;

use ImageMimeTypeGuesser\ImageMimeTypeGuesser;

use WebPConvert\Convert\Exceptions\ConversionFailedException;
use WebPConvert\Serve\Exceptions\ServeFailedException;
use WebPConvert\Serve\Header;
use WebPConvert\Serve\Report;
use WebPConvert\Serve\ServeFile;
use WebPConvert\Options\ArrayOption;
use WebPConvert\Options\BooleanOption;
use WebPConvert\Options\Options;
use WebPConvert\Options\SensitiveArrayOption;
use WebPConvert\Options\Exceptions\InvalidOptionTypeException;
use WebPConvert\Options\Exceptions\InvalidOptionValueException;
use WebPConvert\WebPConvert;

/**
 * Serve a converted webp image.
 *
 * The webp that is served might end up being one of these:
 * - a fresh convertion
 * - the destionation
 * - the original
 *
 * Exactly which is a decision based upon options, file sizes and file modification dates
 * (see the serve method of this class for details)
 *
 * @package    WebPConvert
 * @author     Bjørn Rosell <it@rosell.dk>
 * @since      Class available since Release 2.0.0
 */
class ServeConvertedWebP
{

    /**
     * Process options.
     *
     * @throws \WebPConvert\Options\Exceptions\InvalidOptionTypeException   If the type of an option is invalid
     * @throws \WebPConvert\Options\Exceptions\InvalidOptionValueException  If the value of an option is invalid
     * @param array $options
     */
    private static function processOptions($options)
    {
        $options2 = new Options();
        $options2->addOptions(
            new BooleanOption('reconvert', false),
            new BooleanOption('serve-original', false),
            new BooleanOption('show-report', false),
            new ArrayOption('serve-image', []),
            new SensitiveArrayOption('convert', [])
        );
        foreach ($options as $optionId => $optionValue) {
            $options2->setOrCreateOption($optionId, $optionValue);
        }
        $options2->check();
        return $options2->getOptions();
    }

    /**
     * Serve original file (source).
     *
     * @param   string  $source                        path to source file
     * @param   array   $serveImageOptions (optional)  options for serving an image
     *                  Supported options:
     *                  - All options supported by ServeFile::serve()
     * @throws  ServeFailedException  if source is not an image or mime type cannot be determined
     * @return  void
     */
    public static function serveOriginal($source, $serveImageOptions = [])
    {
        $contentType = ImageMimeTypeGuesser::lenientGuess($source);
        if (is_null($contentType)) {
            throw new ServeFailedException('Rejecting to serve original (mime type cannot be determined)');
        } elseif ($contentType === false) {
            throw new ServeFailedException('Rejecting to serve original (it is not an image)');
        } else {
            ServeFile::serve($source, $contentType, $serveImageOptions);
        }
    }

    /**
     * Serve destination file.
     *
     * @param   string  $destination                   path to destination file
     * @param   array   $serveImageOptions (optional)  options for serving (such as which headers to add)
     *       Supported options:
     *       - All options supported by ServeFile::serve()
     * @return  void
     */
    public static function serveDestination($destination, $serveImageOptions = [])
    {
        ServeFile::serve($destination, 'image/webp', $serveImageOptions);
    }

    /**
     * Serve converted webp.
     *
     * Serve a converted webp. If a file already exists at the destination, that is served (unless it is
     * older than the source - in that case a fresh conversion will be made, or the file at the destination
     * is larger than the source - in that case the source is served). Some options may alter this logic.
     * In case no file exists at the destination, a fresh conversion is made and served.
     *
     * @param   string  $source              path to source file
     * @param   string  $destination         path to destination
     * @param   array   $options (optional)  options for serving/converting
     *       Supported options:
     *       'show-report'     => (boolean)   If true, the decision will always be 'report'
     *       'serve-original'  => (boolean)   If true, the decision will be 'source' (unless above option is set)
     *       'reconvert     '  => (boolean)   If true, the decision will be 'fresh-conversion' (unless one of the
     *                                        above options is set)
     *       - All options supported by WebPConvert::convert()
     *       - All options supported by ServeFile::serve()
     * @param  \WebPConvert\Loggers\BaseLogger $logger (optional)
     *
     * @throws  \WebPConvert\Exceptions\WebPConvertException  If something went wrong.
     * @return  void
     */
    public static function serve($source, $destination, $options = [], $logger = null)
    {
        if (empty($source)) {
            throw new ServeFailedException('Source argument missing');
        }
        if (empty($destination)) {
            throw new ServeFailedException('Destination argument missing');
        }
        if (@!file_exists($source)) {
            throw new ServeFailedException('Source file was not found');
        }

        $options = self::processOptions($options);
//print_r($options); exit;

        //$options = array_merge(self::$defaultOptions, $options);

        // Step 1: Is there a file at the destination? If not, trigger conversion
        // However 1: if "show-report" option is set, serve the report instead
        // However 2: "reconvert" option should also trigger conversion
        if ($options['show-report']) {
            Header::addLogHeader('Showing report', $logger);
            Report::convertAndReport($source, $destination, $options);
            return;
        }

        if (!@file_exists($destination)) {
            Header::addLogHeader('Converting (there were no file at destination)', $logger);
            WebPConvert::convert($source, $destination, $options['convert'], $logger);
        } elseif ($options['reconvert']) {
            Header::addLogHeader('Converting (told to reconvert)', $logger);
            WebPConvert::convert($source, $destination, $options['convert'], $logger);
        } else {
            // Step 2: Is the destination older than the source?
            //         If yes, trigger conversion (deleting destination is implicit)
            $timestampSource = @filemtime($source);
            $timestampDestination = @filemtime($destination);
            if (($timestampSource !== false) &&
                ($timestampDestination !== false) &&
                ($timestampSource > $timestampDestination)) {
                    Header::addLogHeader('Converting (destination was older than the source)', $logger);
                    WebPConvert::convert($source, $destination, $options['convert'], $logger);
            }
        }

        // Step 3: Serve the smallest file (destination or source)
        // However, first check if 'serve-original' is set
        if ($options['serve-original']) {
            Header::addLogHeader('Serving original (told to)', $logger);
            self::serveOriginal($source, $options['serve-image']);
        }

        $filesizeDestination = @filesize($destination);
        $filesizeSource = @filesize($source);
        if (($filesizeSource !== false) &&
            ($filesizeDestination !== false) &&
            ($filesizeDestination > $filesizeSource)) {
                Header::addLogHeader('Serving original (it is smaller)', $logger);
                self::serveOriginal($source, $options['serve-image']);
        }

        Header::addLogHeader('Serving converted file', $logger);
        self::serveDestination($destination, $options['serve-image']);
    }
}
