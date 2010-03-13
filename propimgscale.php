<?php
/*
Plugin Name: Proportional Image Scaling
Plugin URI: http://wordpress.org/extend/plugins/proportional-image-scaling/
Description: This plugin is meant to assist CSS stylesheets in proportionally scaling images in the post using the <code>max-width</code> rule. It will either remove all <em>width</em> and <em>height</em> attributes from images or scale them so that they fit in the given width.
Author: Markus Mayer
Version: 1.0
Author URI: http://blog.defx.de
License: GPL2

    Copyright 2010  Markus Mayer

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

$Id$

*/

class ProportionalImageScaling
{
    // constructor
    function ProportionalImageScaling()
    {
        add_action('init', array(&$this, 'init_variables'), 1000 );
        add_filter('the_content', array(&$this, 'filter'), 1000 );
        add_action('admin_menu', array(&$this, 'register_options_page'), 1000 );
    }

    var $theme_width = 0;
    var $fallback_behavior = 0;
    var $image_classes = array('wp-image-');

    function init_variables()
    {
        // Get options and set defaults
        $options = get_option('proportionalimagescaling_options');
        if( $options === FALSE )
        {
            $options = array();
            $options["width"] = 0;
            $options["imgclass"] = "wp-image-";
            update_option('proportionalimagescaling_options', $options);
        }

        $this->theme_width = $options["width"];
        $this->image_classes = @explode(' ', $options['imgclass']);
        // $this->fallback_behavior = $options['fallback'];
    }

    // filters the content
    function filter($content)
    {
        $contentLength = strlen($content);
        $startindex = 0;
        do
        {
            // find img tag
            $index = stripos($content, "<img", $startindex);
            if($index === FALSE) break; // exit condition
            $startindex = $index;

            // find tag end, starting at the current index
            $charIdx = $index;
            $inAttribute = FALSE;
            do
            {
                $char = $content[++$charIdx]; // this skips the first index, but that's okay.
                if($char == '"') $inAttribute != $inAttribute;
                if($inAttribute) continue;
                if($char == '>') // tag end found
                {
                    // extract the tag
                    $endindex = $charIdx;
                    $length = $endindex - $startindex + 1;
                    $tag = substr($content, $startindex, $length);

                    // get replacement text
                    $replacement = $this->do_scaling_foo($tag, $this->theme_width, $this->image_classes, $this->fallback_behavior);
                    if($replacement === FALSE)
                    {
                        $startindex += $length;
                        break;
                    }

                    // replace text
                    $content = substr_replace($content, $replacement, $startindex, $length);

                    // advance to next start index
                    $startindex += strlen($replacement);
                    break;
                }
            }
            while($charIdx < $contentLength);
        }
        while($startindex < $contentLength);
        return $content;
    }

    // extracts an attribute from the tag
    function extract_attr($tag, $attribute)
    {
        $attrIndex = stripos($tag, $attribute);
        if($attrIndex === FALSE) return FALSE;

        // check if this is the start of the word
        if($attrIndex > 0)
        {
            $pre = $tag[$attrIndex-1];
            if(!ctype_space($pre) && $pre != '"') return FALSE;
        }

        $index = stripos($tag, '=', $attrIndex);
        if($index === FALSE) return FALSE;

        // find attribute end, starting at the current index
        $tagLength = strlen($tag);
        $charIdx = $index;
        $inValue = FALSE;
        $isQuoted = FALSE;
        $valueStart = -1;
        do
        {
            $char = $tag[++$charIdx]; // implicitely skips the '='
            // name = "value"
            // name = value

            // If the value hasn't started ...
            if(!$inValue)
            {
                if($char == ' ') continue;
                if($char == '"') $isQuoted = TRUE;
                $inValue = TRUE;
                continue;
            }

            // mark start of value
            if($valueStart < 0) $valueStart = $charIdx;

            // if the value has started ...
            if($isQuoted == TRUE && $char != '"') continue;
            else if(!$isQuoted && $char != ' ') continue;

            // at this point, the end is nigh
            $endindex = $charIdx;
            $valueEnd = $endindex - 1;
            $length = $valueEnd - $valueStart + 1;
            $value = substr($tag, $valueStart, $length);

            // return value
            $result = array();
            //$result["name"] = $attribute;
            $result["start"] = $attrIndex;
            //$result["end"] = $endindex;
            $result["length"] = $endindex - $attrIndex + 1;
            $result["value"] = $value;
            $result["valueStart"] = $valueStart;
            $result["valueLength"] = $length;
            return $result;
        }
        while($charIdx < $tagLength);
        return FALSE;
    }

    // scales the image (tag) to the given width
    function do_scaling_foo($tag, $target_width, $class_rule = FALSE, $fallback = 0)
    {
        $width = $this->extract_attr($tag, 'width');
        $height = $this->extract_attr($tag, 'height');

        // If there is neither height nor width, exit.
        if($width === FALSE && $height === FALSE) return $tag;

        // perform width check, only scale large images
        // this is faster than the class check, so we do it first.
        $w = (int)$width["value"];
        if($w <= $target_width || $w <= 0) return FALSE;

        // check class attribute, skip non-wp-image-... images
        if($class_rule !== FALSE && !empty($class_rule))
        {
            $class = $this->extract_attr($tag, 'class');
            foreach($class_rule as $term)
            {
                if(empty($term)) continue;
                if(stripos($class["value"], $term) === FALSE) return FALSE;
            }
        }

        // check fallback behavior
        if($width === FALSE || $height === FALSE)
        {
            // TODO: "Delete attributes" option.
            return FALSE;
        }

        // scale values
        $h = (int)$height["value"];
        $scaled_height = (int)($h*(int)$target_width/$w + 0.5);

        // replacement array
        $replacement = array();
        if($width["valueStart"] < $height["valueStart"]) // width comes before height attribute
        {
            if($target_width > 0) // if scaling is enabled
            {
                $width_diff = strlen($target_width) - $width["valueLength"];
                $height_start = $height["valueStart"] + $width_diff;
                $height_length = $height["valueLength"];

                $width_start = $width["valueStart"];
                $width_length = $width["valueLength"];

                $width_value = $target_width;
                $height_value = $scaled_height;
            }
            else // remove entirely
            {
                $height_start = $height["start"] -$width["length"];
                $height_length = $height["length"];

                $width_start = $width["start"];
                $width_length = $width["length"];

                $width_value = '';
                $height_value = '';
            }

            $replacement[] = array('value' => $width_value, 'start' => $width_start, 'length' => $width_length);
            $replacement[] = array('value' => $height_value, 'start' => $height_start, 'length' => $height_length);
        }
        else // height attribue comes before width attribute
        {
            if($target_width > 0) // scale
            {
                $height_diff = strlen($scaled_height) - $height["valueLength"];
                $width_start = $width["valueStart"] + $height_diff;
                $width_length = $width["valueLength"];

                $height_start = $height["valueStart"];
                $height_length = $height["valueLength"];

                $width_value = $target_width;
                $height_value = $scaled_height;
            }
            else // remove
            {
                $width_start = $width["start"] - $height["length"];
                $width_length = $width["length"];

                $height_start = $height["start"];
                $height_length = $height["length"];

                $width_value = '';
                $height_value = '';
            }

            $replacement[] = array('value' => $height_value, 'start' => $height_start, 'length' => $height_length);
            $replacement[] = array('value' => $width_value, 'start' => $width_start, 'length' => $width_length);
        }

        // replace the values
        $tag = substr_replace($tag, $replacement[0]['value'], $replacement[0]['start'], $replacement[0]['length']);
        $tag = substr_replace($tag, $replacement[1]['value'], $replacement[1]['start'], $replacement[1]['length']);

        return $tag;
    }

    function register_options_page()
    {
        if ( function_exists('add_options_page') )
            add_options_page(__('Proportional Image Scaling', 'propimgscale'), __('Proportional Image Scaling', 'propimgscale'), 8, __FILE__, array(&$this, 'options_menu'));
    }

    function options_menu()
    {
        $options = get_option('proportionalimagescaling_options');

        if ( isset($_POST['Submit']) ) {
            check_admin_referer('proportionalimagescaling-update-options');
            $options['width'] = max(0, (int)$_POST['width']);
            $options['imgclass'] = $_POST['imgclass'];
            //$options['fallback'] = min(max(0, (int)$_POST['fallback']), 1);
            update_option('proportionalimagescaling_options', $options);
            echo '<div id="message" class="updated fade"><p><strong>' . __('Settings saved.', 'propimgscale') . '</strong></p></div>';
        }

        //$fallback = $options['fallback'];
        //if(empty($fallback)) $fallback = 0;

    ?>
        <div class="wrap">
            <h2><?php _e('Proportional Image Scaling', 'propimgscale'); ?></h2>
            <form action="" method="post" id="proportionalimagescaling" accept-charset="utf-8">
                <h3><?php _e('Basic settings:', 'propimgscale') ?></h3>
                <p><?php _e('This plugin is meant to assist CSS stylesheets in proportionally scaling images in the post using the <code>max-width</code> rule.<br />It will either remove all <em>width</em> and <em>height</em> attributes from images or scale them so that they fit in the given width.', 'propimgscale') ?></p>
                <h3><?php _e('Basic settings:', 'propimgscale') ?></h3>
                <table>
                <tr style="vertical-align: top;">
                    <td><label for="width"><?php _e('Theme width:', 'propimgscale') ?></label></td>
                    <td style="padding-left: 20px;">
                        <input id="width" name="width" style="text-align: right;" value="<?php echo max(0, (int)$options['width']) ?>" /> px<br/>
                        <?php _e("Set this value to <code>0</code> to remove <em>height</em> and <em>width</em> attributes.", 'propimgscale') ?>
                    </td>
                </tr>
                <tr style="vertical-align: top;">
                    <td><label for="imgclass"><?php _e('Image class:', 'propimgscale') ?></label></td>
                    <td style="padding-left: 20px;">
                        <input id="imgclass" name="imgclass"" value="<?php echo $options['imgclass'] ?>" /> <?php _e("(e.g. <code>wp-image-</code>)", 'propimgscale') ?><br />
                        <?php _e("A space separated list of terms that have to be in the images' class attribute in order to activate the resizing process.<br />If no term is set, every image tag will be processed.", 'propimgscale'); ?>
                    </td>
                </tr>
                <?php /*
                <tr style="vertical-align: top;">
                    <td><?php _e('Fallback behavior:') ?></td>
                    <td style="padding-left: 20px;">
                        <input <?php if($fallback == 0) echo 'checked="checked"' ?> type="radio" id="donothing" name="fallback" value="0" /><label for="donothing"><?php _e('Do nothing.') ?></label><br />
                        <input <?php if($fallback == 1) echo 'checked="checked"' ?>type="radio" id="delete" name="fallback" value="1" /><label for="delete"><?php _e('Remove attributes.') ?></label>
                    </td>
                </tr>
                */ ?>
                </table>

                <?php wp_nonce_field('proportionalimagescaling-update-options'); ?>
                <p class="submit"><input type="submit" name="Submit" class="button-primary" value="<?php _e('Save Changes', 'propimgscale') ?>"/></p>
            </form>
        </div>
    <?php
    }
}

// create the instance
$ProportionalImageScaler = new ProportionalImageScaling();